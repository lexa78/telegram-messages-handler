<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Patterns\Adapters\Exchange\AbstractExchangeApi;
use App\Patterns\Factories\ExchangeFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Обработка данных из канала Wot
 */
class WotChannelJob extends AbstractChannelJob
{
    private function parseSignal(string $text): array
    {
        $parsed = [
            'coin' => null,
            'direction' => null,
            'entryRange' => null,
            'entrySingle' => null,
            'stopLoss' => null,
            'leverage' => null,
        ];

        // Direction + coin
        if (preg_match('/(?:coin\s*:?\s*)?\$?#?([A-Z0-9\/]+)\s+(long|short)\b/i', $text, $m)) {
            $parsed['coin'] = $m[1] ?? null;
            $parsed['direction'] = $m[2] ?? null;
        }

        // leverage
        if (preg_match('/leverage\s*:\s*(?:isolated\s*)?x(\d+)/i', $text, $m)) {
            $parsed['leverage'] = $m[1] ?? null;
        }

        // entryRange
        if (preg_match('/entry\s*:\s*([\d.,]+)\s*-\s*([\d.,]+)/i', $text, $mRange)) {
            $entry = [];
            if ($mRange !== []) {
                $entry[] = str_replace(',', '', $mRange[1]);
                $entry[] = str_replace(',', '', $mRange[2]);
            }

            $parsed['entryRange'] = $entry;
        }

        // entrySingle
        if (preg_match_all('/entry\s*\d*\s*:\s*([\d.,]+)/i', $text, $mSingle)) {
            $entry = [];
            foreach ($mSingle[1] ?? [] as $v) {
                $entry[] = str_replace(',', '', $v);
            }

            $parsed['entrySingle'] = $entry;
        }

        // STOP LOSS
        if (preg_match('/\b(?:sl|stop(?:loss)?)\s*:\s*([\d.,]+)/i', $text, $m)) {
            if (isset($m[1])) {
                $parsed['stopLoss'] = str_replace(',', '', $m[1]);
            }
        }

        // Targets (TP1, TP2, TP3…)
        preg_match_all('/(?:target|tp)\s*\d*\s*[:\-]?\s*([\d.,]+)/i', $text, $mT1);

        preg_match('/targets\s*:\s*([^\n]+)/i', $text, $mT2);

        $targets = [];

        // Target 1: ...
        foreach ($mT1[1] ?? [] as $v) {
            $targets[] = str_replace(',', '', $v);
        }

        // Targets: 92,780 - 96,624 - ...
        if (!empty($mT2[1])) {
            preg_match_all('/[\d.,]+/', $mT2[1], $nums);
            foreach ($nums[0] as $v) {
                $targets[] = str_replace(',', '', $v);
            }
        }

        $parsed['targets'] = array_values(array_unique($targets));

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

        $entry = [];
        if (is_array($parseResult['entryRange'])) {
            foreach ($parseResult['entryRange'] as $entryValue) {
                $entry[] = (float) str_replace(',', '.', (string) $entryValue);
            }
        }
        if (is_array($parseResult['entrySingle'])) {
            foreach ($parseResult['entrySingle'] as $entryValue) {
                $entry[] = (float) str_replace(',', '.', (string) $entryValue);
            }
        }

        $setOrderData = [
            'channelId' => $this->data['channelId'],
            'symbol' => $parseResult['coin'],
            'direction' => $direction,
            'entry' => $entry === [] ? self::NOT_FOUND_PLACEHOLDER : collect($entry)->avg(),
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
