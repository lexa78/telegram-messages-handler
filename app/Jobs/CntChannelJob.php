<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Patterns\Adapters\Exchange\AbstractExchangeApi;
use App\Patterns\Factories\ExchangeFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Обработка данных из канала Cnt
 */
class CntChannelJob extends AbstractChannelJob
{
    /**
     * Выбираем нужные данные
     */
    private function parseSignal(string $text): array
    {
        $parsed = [
            'coin' => null,
            'direction' => null,
            'entry' => null,
            'limitEntry' => null,
            'stopLoss' => null,
            'targets' => [],
        ];

        // Direction + coin
        if (preg_match('/\b(Long|Short)\b.*?\$?([A-Z0-9]+)/iu', $text, $m)) {
            $parsed['direction'] = ucfirst(strtolower($m[1]));
            $parsed['coin'] = strtoupper($m[2]);
        }

        // ENTRY
        if (preg_match('/\bentry\b(?!\s*limit)\s*[:\-]?\s*(\d+\.\d+)(?:\s*-\s*(\d+\.\d+))?/i', $text, $mEntry)) {
            $parsed['entry'] = [$mEntry[1] ?? null, $mEntry[2] ?? null];
        }

        // LIMIT ENTRY
        if (preg_match_all('/\b(?:entry\s*limit|limit\s*entry)[\s\d]*[:\-]?\s*(\d+\.\d+)/i', $text, $mLimitEntry)) {
            $parsed['limitEntry'] = $mLimitEntry[1] ?? [];
        }

        // STOP LOSS
        if (preg_match('/\b(?:sl|stop(?:loss)?)\s*[:\-]?\s*(\d+\.\d+)/i', $text, $mSL)) {
            $parsed['stopLoss'] = $mSL[1] ?? null;
        }

        // Targets (TP1, TP2, TP3…)
        if (preg_match_all('/TP\d+:\s*([\d\.]+)/iu', $text, $m)) {
            $parsed['targets'] = $m[1];
        }

        return $parsed;
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
            'symbol' => $parseResult['coin'],
            'direction' => $direction,
            'entry' => self::NOT_FOUND_PLACEHOLDER, //todo проверить, что рыночная цена не выходит за entry, если выходит, то надо будет написать пересчет на limit entry
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
