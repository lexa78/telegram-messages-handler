<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Patterns\Adapters\Exchange\AbstractExchangeApi;
use App\Patterns\Factories\ExchangeFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Обработка данных из канала Et
 */
class EtChannelJob extends AbstractChannelJob
{
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
        $parseResult = preg_match(
            '/💰\s*([A-Z0-9]+)\s+(long|short)\s+([0-9\-xх]+).*?Вход:\s*([^\n]+).*?Цели:\s*([\d\.\,\s]+).*?Стоп:\s*([\d\.]+)/isu',
            $message,
            $match
        );
        if ($parseResult === false || $parseResult === 0) {
            Log::channel('skippedMessagesFromJob')
                ->error('Parsing failed', ['msg' => $message, 'channelId' => $this->data['channelId']]);
            return;
        }

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

        $direction = $match[2] ?? null;
        if ($direction !== null) {
            $direction = trim(Str::lower($direction));
            $direction = $direction === 'long' ? AbstractExchangeApi::LONG_DIRECTION : AbstractExchangeApi::SHORT_DIRECTION;
        }

        $leverage = $match[3] ?? null;
        if ($leverage === null) {
            $leverage = 10;
        } else {
            $leverage = Str::replace(['х', 'x'], '', $leverage); // русскую х -> английскую x
            if (Str::contains($leverage, '-')) {
                $levArr = array_map('trim', explode('-', $leverage));
                $levArr = array_map(
                    function (string $item) {
                        return (int) $item;
                    },
                    $levArr,
                );
                $leverage = (int) round(array_sum($levArr) / count($levArr));
            }
        }

        $entry = $m[4] ?? null;
        if ($entry !== null) {
            $entry = (float) trim($entry);
        }

        $targets = $match[5] ?? null;
        if ($targets !== null) {
            $targets = array_values(
                array_filter(
                    array_map('trim', explode(',', $match[5]))
                )
            );
            foreach ($targets as &$target) {
                $target = (float) str_replace(',', '.', (string) $target);
            }
            unset($target);
        }

        $stopLoss = $match[6] ?? null;
        if ($stopLoss !== null) {
            $stopLoss = (float) str_replace(',', '.', (string) $stopLoss);
        } else {
            $stopLoss = self::NOT_FOUND_PLACEHOLDER;
        }

        $setOrderData = [
            'channelId' => $this->data['channelId'],
            'symbol' => $match[1] ?? null,
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
