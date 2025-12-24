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
            '/^(\S+)\s+📈\s+(LONG|SHORT)\s+x(\d+).*?Вход:.*?Рынок\s*([\d.,]+).*?Лимит\s*([\d.,]+).*?Тake-Profit:(.*?)(?:❌|$).*?Stop-loss:\s*([\d.,]+)/siu',
            $message,
            $match
        );
        if ($parseResult === false || $parseResult === 0) {
            Log::channel('skippedMessagesFromJob')
                ->error('Parsing failed', ['msg' => $message, 'channelId' => $this->data['channelId']]);
            return;
        }

        // Вытаскиваем все Targets
        $subject = $message;
        if (!empty($match[6])) {
            $subject = $match[6];
        }
        preg_match_all('/\d\)\s*([\d.,]+)/', $subject, $targets);
        $targets = $targets[1] ?? null;

        $entryFrom = $match[4] ?? null;
        $entryTo = $match[5] ?? null;
        if (empty($entryFrom) && empty($entryTo)) {
            $entry = null;
        } elseif (empty($entryFrom) || empty($entryTo)) {
            $entryFrom = empty($entryFrom) ? 0 : (float) str_replace(',', '.', (string) $entryFrom);
            $entryTo = empty($entryTo) ? 0 : (float) str_replace(',', '.', (string) $entryTo);
            $entry = $entryFrom + $entryTo;
        } else {
            $entryFrom = (float) str_replace(',', '.', (string) $entryFrom);
            $entryTo = (float) str_replace(',', '.', (string) $entryTo);
            $entry = ($entryFrom + $entryTo) / 2;
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

        $leverage = empty($match[3]) ? 10 : (int) $match[3];

        if (! empty($targets)) {
            if (is_array($targets)) {
                foreach ($targets as &$target) {
                    $target = (float) str_replace(',', '.', (string) $target);
                }
                unset($target);
            } else {
                $targets = (float) str_replace(',', '.', (string) $targets);
            }
        }

        $stopLoss = $match[7] ?? null;
        if ($stopLoss !== null) {
            $stopLoss = (float) str_replace(',', '.', (string) $stopLoss);
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
