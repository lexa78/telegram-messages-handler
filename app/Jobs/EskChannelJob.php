<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Patterns\Adapters\Exchange\AbstractExchangeApi;
use App\Patterns\Factories\ExchangeFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Обработка данных из канала Esk
 */
class EskChannelJob extends AbstractChannelJob
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
            'stopLoss' => null,
            'targets' => [],
            'leverage' => null,
        ];

        // Direction + coin + leverage
        if (preg_match(
            '/#([A-Z0-9]+)\s+(LONG|SHORT)\s+X(\d+(?:-(\d+))?)/i',
            $text,
            $m
        )) {
            $parsed['coin'] = $m[1] ?? null;
            $parsed['direction'] = strtoupper($m[2] ?? '');
            if (isset($m[4]) && Str::contains($m[3], '-')) {
                $parsed['leverage'] = (int) collect(explode('-', $m[3]))->avg();
            } else {
                $parsed['leverage'] = $m[3] ?? null;
            }
        }

        // STOP LOSS
        if (preg_match(
            '/(?:стоп|sl|stop)[^0-9]{0,30}(\d+[.,]?\d*)/iu',
            $text,
            $m
        )) {
            $parsed['stopLoss'] = isset($m[1])
                ? str_replace(',', '.', $m[1])
                : self::NOT_FOUND_PLACEHOLDER;
        }
        // Targets (TP1, TP2, TP3…)
        if (preg_match(
            '/(?:тейк|tp|target)[^0-9]{0,30}((?:\d+[.,]?\d*)(?:\s*(?:\/|и)\s*(?:\d+[.,]?\d*))*)/iu',
            $text,
            $m
        )) {
            if (!empty($m[1])) {
                $targetsRaw = str_replace(',', '.', $m[1]);
                $targets = preg_split('/\s*(?:\/|и)\s*/u', $targetsRaw);
                $parsed['targets'] = array_values(array_filter($targets, fn($v) => is_numeric($v)));
            }
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
            'entry' => self::NOT_FOUND_PLACEHOLDER,
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
