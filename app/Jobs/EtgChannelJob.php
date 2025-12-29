<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Patterns\Adapters\Exchange\AbstractExchangeApi;
use App\Patterns\Factories\ExchangeFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Обработка данных из канала Etg
 */
class EtgChannelJob extends AbstractChannelJob
{
    /**
     * Получение информации из пришедшего сообщения
     */
    private function parseSignal(string $text): array
    {
        $result = [
            'coin' => null,
            'direction' => null,
            'leverage' => null,
            'entry' => [],
            'targets' => [],
            'stopLoss' => null,
        ];

        // direction, coin, leverage
        if (preg_match(
            '/\b(long|short)\b.*?\$([A-Z0-9]+).*?\(max\s*(\d+)x\)/iu',
            $text,
            $m
        )) {
            $result['direction'] = strtoupper($m[1] ?? null);
            $result['coin'] = $m[2] ?? null;
            $result['leverage'] = $m[3] ?? null;
        }

        // entry
        if (preg_match(
            '/entry\s*:\s*([\d.]+)\s*-\s*([\d.]+)/i',
            $text,
            $m
        )) {
            $result['entry'] = [
                $m[1] ?? null,
                $m[2] ?? null,
            ];
        }

        // targets
        if (preg_match(
            '/tp\s*:\s*([^\n]+)/i',
            $text,
            $m
        )) {
            if (!empty($m[1])) {
                preg_match_all('/[\d.]+/', $m[1], $nums);
                $result['targets'] = $nums[0];
            }
        }

        // stop loss
        if (preg_match(
            '/\bsl\s*:\s*([\d.]+)/i',
            $text,
            $m
        )) {
            $result['stopLoss'] = $m[1] ?? null;
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

        if (!$this->checkIfItNecessaryMessage($message)) {
            // если в сообщении ничего интересного, игнорируем
            return;
        }

        // парсим сообщение и получаем необходимые данные
        $parseResult = $this->parseSignal($message);

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

        $direction = $parseResult['direction'] ?? null;
        if ($direction !== null) {
            $direction = trim(Str::lower($direction));
            $direction = $direction === 'long' ? AbstractExchangeApi::LONG_DIRECTION : AbstractExchangeApi::SHORT_DIRECTION;
        }

        $leverage = empty($parseResult['leverage']) ? 10 : (int) $parseResult['leverage'];

        $targets = null;
        if (! empty($parseResult['targets'])) {
            $targets = $parseResult['targets'];
            if (is_array($targets)) {
                foreach ($targets as &$target) {
                    $target = (float) str_replace(',', '.', (string) $target);
                }
                unset($target);
            } else {
                $targets = (float) str_replace(',', '.', (string) $targets);
            }
        }

        $stopLoss = $parseResult['stopLoss'] ?? null;
        if ($stopLoss !== null) {
            $stopLoss = (float) str_replace(',', '.', (string) $stopLoss);
        } else {
            $stopLoss = self::NOT_FOUND_PLACEHOLDER;
        }

        $setOrderData = [
            'channelId' => $this->data['channelId'],
            'symbol' => $parseResult['coin'] ?? null,
            'direction' => $direction,
            'entry' => $parseResult['entry'] ?? self::NOT_FOUND_PLACEHOLDER,
            'leverage' => $leverage,
            'targets' => $targets,
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
