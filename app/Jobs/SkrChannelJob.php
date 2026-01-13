<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Patterns\Adapters\Exchange\AbstractExchangeApi;
use App\Patterns\Factories\ExchangeFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Обработка данных из канала Skr
 */
class SkrChannelJob extends AbstractChannelJob
{
    /**
     * Выбираем нужные данные
     */
    private function parseSignal(string $text): array
    {
        $parsed = [
            'coin' => null,
            'direction' => null,
            'marketEntry' => null,
            'limitEntry' => null,
            'stopLoss' => null,
            'targets' => [],
            'leverage' => null,
        ];

        // Direction + coin + leverage
        if (preg_match('/(\S+)\s+📈\s+(LONG|SHORT)[\s\x{00A0}]+x?(\d+(?:-\d+)?)x?/iu', $text, $m)) {
            $parsed['coin'] = $m[1] ?? null;
            $parsed['direction'] = $m[2] ?? null;
            $leverage = $m[3] ?? null;
            if ($leverage === null) {
                $leverage = 10;
            } else {
                if (Str::contains($leverage, '-')) {
                    $leverages = explode('-', $leverage);
                    $leverage = collect($leverages)->avg();
                }
            }
            $parsed['leverage'] = (int) $leverage;
        }

        // marketEntry
        if (preg_match('/рынок[\s\x{00A0}]+([\d,.]+)/iu', $text, $m)) {
            $parsed['marketEntry'] = $m[1] ?? null;
        }
        // limitEntry
        if (preg_match('/лимит[\s\x{00A0}]+([\d,.]+)/iu', $text, $m)) {
            $parsed['limitEntry'] = $m[1] ?? null;
        }

        // STOP LOSS
        if (preg_match('/stop[\s\-]?loss\s*:\s*([\d,.]+)/iu', $text, $m)) {
            $parsed['stopLoss'] = $m[1] ?? null;
        }

        // Targets (TP1, TP2, TP3…)
        if (preg_match_all('/\d+\)\s*([\d,.]+)/u', $text, $m)) {
            $parsed['targets'] = $m[1] ?? [];
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

        $entry = [];
        if ($parseResult['marketEntry'] !== null) {
            $entry[] = (float) str_replace(',', '.', (string) $parseResult['marketEntry']);
        }
        if ($parseResult['limitEntry'] !== null) {
            $entry[] = (float) str_replace(',', '.', (string) $parseResult['limitEntry']);
        }
        if ($entry === []) {
            $entry = self::NOT_FOUND_PLACEHOLDER;
        }

        $setOrderData = [
            'channelId' => $this->data['channelId'],
            'symbol' => $parseResult['coin'],
            'direction' => $direction,
            'entry' => $entry,
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
