<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Patterns\Factories\ExchangeFactory;
use Illuminate\Support\Facades\Log;

/**
 * Обработка данных из канала Svr
 */
class SvrChannelJob extends AbstractChannelJob
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
            '/Оформляем\s+(\S+)\s*\/\s*(LONG|SHORT).*?Плечи:\s*([\d.,]+)-([\d.,]+)х.*?Вход.*?Рынок\s*([\d.,]+).*?(?:и|и Лимитный ордер)\s*([\d.,]+).*?Тэйк-профит:\s*([0-9.,\s]+).*?Стоп-лосс:\s*([\d.,]+)/siu',
            $message,
            $match
        );
        if ($parseResult === false || $parseResult === 0) {
            Log::channel('skippedMessagesFromJob')
                ->error('Parsing failed', ['msg' => $message, 'channelId' => $this->data['channelId']]);
            return;
        }

        // Разбиваем тейки
        $targets = null;
        if (!empty($match[7])) {
            $targets = array_map('trim', explode(',', $match[7]));
        }

        $entryFrom = $match[5] ?? null;
        $entryTo = $match[6] ?? null;
        $entry = (empty($entryFrom) && empty($entryTo)) ? null : [$entryFrom, $entryTo];

        $leverageFrom = $match[3] ?? null;
        $leverageTo = $match[4] ?? null;
        $leverage = (empty($leverageFrom) && empty($leverageTo))
            ? null
            : (int) ceil(((int) $leverageFrom + (int) $leverageTo) / 2);
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
            'leverage' => ($leverage > 0 ? $leverage : 10),
            'targets' => $targets,
            'stopLoss' => $match[8] ?? null,
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
