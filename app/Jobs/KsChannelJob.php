<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Cache\CacheKeysEnum;
use App\Patterns\Adapters\Exchange\AbstractExchangeApi;
use App\Patterns\Factories\ExchangeFactory;
use App\Services\AbstractCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Обработка данных из канала Ks
 */
class KsChannelJob extends AbstractChannelJob
{
    /**
     * Получение информации из пришедшего и закешированного сообщений
     */
    private function parseSignal(string $message, string $cachedMessage): array
    {
        $result = [
            'coin' => null,
            'direction' => null,
            'leverage' => 10,
            'entry' => [],
            'targets' => self::NOT_FOUND_PLACEHOLDER,
            'stopLoss' => null,
        ];

        if (preg_match('/(\w+)\s*-\s*(\w+)/i', $cachedMessage, $match)) {
            $result['coin'] = $match[1] ?? null;
            $result['direction'] = $match[2] ?? null;
        }

        if (preg_match('/твх\s*(\d\.\d{2,})/i', $message, $match)) {
            $result['entry'] = $match[1] ?? null;
            if ($result['entry'] !== null) {
                $result['entry'] = [$result['entry']];
            }
        }

        if (preg_match('/стоп\s*(\d\.\d{2,})/i', $message, $match)) {
            $result['stopLoss'] = $match[1] ?? null;
        }

        return $result;
    }

    public function handle(): void
    {
        if (!isset($this->data['data']['message']['message'])) {
            Log::channel('skippedMessagesFromJob')
                ->error('Message not found', ['cameData' => $this->data]);
            return;
        }
        $message = $this->data['data']['message']['message'];

        if (!isset($this->data['data']['message']['id'])) {
            Log::channel('skippedMessagesFromJob')
                ->error('Message ID not found', ['cameData' => $this->data]);
            return;
        }
        $messageCacheKey = CacheKeysEnum::KsChannelMessages
            ->getKeyForKsMessage((string) $this->data['data']['message']['id']);
        Cache::put($messageCacheKey, $message, AbstractCacheService::HALF_OF_HOUR_CACHE_TTL);

        if (!$this->checkIfItNecessaryMessage($message)) {
            // если в сообщении ничего интересного, игнорируем
            return;
        }

        // пытаемся найти сообщение, в ответ на которое пришло это
        if (!isset($this->data['data']['message']['reply_to']['reply_to_msg_id'])) {
            Log::channel('skippedMessagesFromJob')
                ->error('Message reply_to_msg_id not found', ['cameData' => $this->data]);
            return;
        }
        $messageCacheKey = CacheKeysEnum::KsChannelMessages
            ->getKeyForKsMessage((string) $this->data['data']['message']['reply_to']['reply_to_msg_id']);
        $cachedMessage = Cache::get($messageCacheKey);
        if ($cachedMessage === null) {
            Log::channel('skippedMessagesFromJob')
                ->error(
                    'Message with id = '
                    . $this->data['data']['message']['reply_to']['reply_to_msg_id']
                    . ' not found in Cache',
                    ['cameData' => $this->data],
                );
            return;
        }
        Cache::forget($messageCacheKey);

        // наименование биржи, по этому ключу фабрика сформирует нужный API объект
        $exchangeName = $this->getDefaultExchange();

        if (empty($exchangeName)) {
            Log::channel('skippedMessagesFromJob')
                ->error(
                    'The environment variable "DEFAULT_EXCHANGE_FOR_TADE" is missing.',
                    ['msg' => $message, 'channelId' => $this->data['channelId']],
                );
            return;
        }

        // парсим сообщение и получаем необходимые данные
        $resultOfParse = $this->parseSignal($message, $cachedMessage);
        $direction = $resultOfParse['direction'];
        if ($direction !== null) {
            $direction = trim(Str::lower($direction));
            $direction = $direction === 'long' ? AbstractExchangeApi::LONG_DIRECTION : AbstractExchangeApi::SHORT_DIRECTION;
        }

        $stopLoss = $resultOfParse['stopLoss'];
        if ($stopLoss !== null) {
            $stopLoss = (float) str_replace(',', '.', (string) $stopLoss);
        }

        $setOrderData = [
            'channelId' => $this->data['channelId'],
            'symbol' => $resultOfParse['coin'],
            'direction' => $direction,
            'entry' => $resultOfParse['entry'],
            'leverage' => $resultOfParse['leverage'],
            'targets' => $resultOfParse['targets'],
            'stopLoss' => $stopLoss,
        ];

        if (!$this->checkIfAllNecessaryDataPresent($setOrderData)) {
            Log::channel('skippedMessagesFromJob')
                ->error(
                    'Necessary values are not found.',
                    [
                        'msg' => $message,
                        'channelId' => $this->data['channelId'],
                        'orderData' => $setOrderData,
                    ],
                );
            return;
        }

        if (!is_array($setOrderData['targets'])) {
            $setOrderData['targets'] = [$setOrderData['targets']];
        }

        // Создаём нужный объект через фабрику
        $exchangeJob = ExchangeFactory::make($exchangeName, $setOrderData);

        if ($exchangeJob === null) {
            Log::channel('skippedMessagesFromJob')
                ->error(
                    'The factory ExchangeFactory did not create an object of type '.$setOrderData['exchange'].'Api.',
                    [
                        'msg' => $message,
                        'channelId' => $this->data['channelId'],
                    ],
                );
            return;
        }

        unset($setOrderData['exchange']);

        // Отправляем данные в очередь для отправки данных в биржу
        $queue = config('queueNames.exchange');
        if ($queue === null) {
            Log::channel('skippedMessagesFromJob')
                ->error(
                    'The file config/queueNames.php don\'t contain queue name for exchange messages.',
                    [
                        'msg' => $message,
                        'channelId' => $this->data['channelId'],
                    ],
                );
            return;
        }

        dispatch($exchangeJob)->onQueue($queue);
    }
}
