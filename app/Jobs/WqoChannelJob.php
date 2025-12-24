<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Patterns\Adapters\Exchange\AbstractExchangeApi;
use App\Patterns\Factories\ExchangeFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Обработка данных из канала Wqo
 */
class WqoChannelJob extends AbstractChannelJob
{
    /**
     * Получение нужной информации из сообщения
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

        // 1. COIN
        if (preg_match('/Coin:\s*#?([A-Z0-9]+)/i', $text, $m)) {
            $result['coin'] = strtoupper($m[1]);
        }

        // 2. DIRECTION
        if (preg_match('/Direction:\s*(Long|Short)/i', $text, $m)) {
            $result['direction'] = ucfirst(strtolower($m[1]));
        }

        // LEVERAGE
        if (preg_match('/(?:Leverage|Плечи)\s*:\s*([0-9]+(?:\s*[-–]\s*[0-9]+)?\s*[xх])/iu', $text, $m)) {
            $lev = trim($m[1]);
            $lev = Str::replace(['х', 'x'], '', $lev); // русскую х -> английскую x

            if (Str::contains($lev,'-')) {
                $levArr = array_map('trim', explode('-', $lev));
                $levArr = array_map(
                    function (string $item) {
                        return (int) $item;
                    },
                    $levArr,
                );
                $lev = (int) round(array_sum($levArr) / count($levArr));
            }

            $result['leverage'] = $lev;
        }

        // 4. ENTRY (Market Price)
        if (preg_match('/Entry:\s*Market/i', $text)) {
            $result['entry'] = ['market'];
        }
        // Entry range / single values
        elseif (preg_match('/Entry:\s*\$?([\d\.]+)\s*[-–]\s*\$?([\d\.]+)/i', $text, $m)) {
            $result['entry'] = [$m[1], $m[2]];
        }
        // One value
        elseif (preg_match('/Entry:\s*\$?([\d\.]+)/i', $text, $m)) {
            $result['entry'] = [$m[1]];
        }

        // 5. TARGETS
        if (preg_match('/Target[s]?:\s*([^\n]+)/i', $text, $m)) {
            $targetsRaw = $m[1];

            // Убираем всё лишнее
            $targetsRaw = preg_replace('/[^0-9\.\-\s]/', '', $targetsRaw);

            // Дробим по "-" и пробелам
            $targets = preg_split('/[\s\-–]+/', trim($targetsRaw));

            // Фильтруем пустые и сортируем как строки (не обязательно)
            $targets = array_values(array_filter($targets, fn($v) => $v !== '' && is_numeric($v)));

            $result['targets'] = $targets;
        }

        // 6. STOP LOSS  (универсальный)
        if (preg_match('/(?:Stop[\s\-]?loss|SL)\s*[:\-]?\s*\$?\s*([\d\.]+)/i', $text, $m)) {
            $result['stopLoss'] = $m[1];
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
