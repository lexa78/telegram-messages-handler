<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Patterns\Factories\ExchangeFactory;
use Illuminate\Support\Facades\Log;

/**
 * Обработка данных из канала BKV
 */
class BkvChannelJob extends AbstractChannelJob
{
    /**
     * todo после того, как все будет сделано, нужно будет подумать, как правильно разделить
     * эту Job чтобы не нарушать принцип SRP
     * пока идея такая
     * Job 1: ChannelMessageParseJob
     *
     * — парсит сообщение Telegram
     * — создаёт нормализованные данные (symbol, entry, targets…)
     *
     * Job 2: CreateExchangeOrderJob
     *
     * — отправляет запрос в биржу
     *
     */
    public function __construct(private readonly array $data)
    {
    }

    public function handle(): void
    {
        if (!isset($this->data['data']['message']['message'])) {
            // todo придумать, что делать с такими сообщениями
            return;
        }
        $message = $this->data['data']['message']['message'];

        if (!$this->checkIfItNecessaryMessage($message)) {
            // если в сообщении ничего интересного, игнорируем
            return;
        }

        // парсим сообщение и получаем необходимые данные
        $parseResult = preg_match(
            '/📍Coin\s*:\s*#(\S+).*?🟢\s*(\w+).*?➡️ Entry:\s*([\d.]+)\s*-\s*([\d.]+).*?🌐 Leverage:\s*(\d+)x.*?(🎯 Target.*)/s',
            $message,
            $match,
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
        preg_match_all('/🎯 Target \d+:\s*([\d.]+)/', $subject, $targets);
        $targets = $targets[1] ?? null;

        // Вытаскиваем StopLoss
        preg_match('/❌ StopLoss:\s*([\d.]+)/', $message, $sl);

        $entryFrom = $match[3] ?? null;
        $entryTo = $match[4] ?? null;
        $entry = (empty($entryFrom) && empty($entryTo)) ? null : [$entryFrom, $entryTo];

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

        $setOrderData = [
            'exchange' => $exchangeName,
            'channelId' => $this->data['channelId'],
            'symbol' => $match[1] ?? null,
            'side' => $match[2] ?? null,
            'entry' => $entry,
            'leverage' => $match[5] ?? 10,
            'targets' => $targets,
            'stopLoss' => $sl[1] ?? null,
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

        // Создаём нужный объект через фабрику
        $exchangeJob = ExchangeFactory::make($setOrderData['exchange'], $setOrderData);

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
