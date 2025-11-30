<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EnvData\EmptyNecessaryDotEnvKeyException;
use App\Exceptions\Factories\FactoryDidntCreateObjectException;
use App\Patterns\Factories\ExchangeFactory;

/**
 * Обработка данных из канала BKV
 */
class BkvChannelJob extends AbstractChannelJob
{
    public int $tries = 3;
    public int $backoff = 5; // секунды

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
        preg_match(
            '/📍Coin\s*:\s*#(\S+).*?🟢\s*(\w+).*?➡️ Entry:\s*([\d.]+)\s*-\s*([\d.]+).*?🌐 Leverage:\s*(\d+)x.*?(🎯 Target.*)/s',
            $message,
            $match,
        );

        // Вытаскиваем все Targets
        preg_match_all('/🎯 Target \d+:\s*([\d.]+)/', $match[6], $targets);
        $targets = $targets[1] ?? null;

        // Вытаскиваем StopLoss
        preg_match('/❌ StopLoss:\s*([\d.]+)/', $message, $sl);

        $entryFrom = $match[3] ?? null;
        $entryTo = $match[4] ?? null;
        $entry = (empty($entryFrom) && empty($entryTo)) ? null : [$entryFrom, $entryTo];

        // наименование биржи, по этому ключу фабрика сформирует нужный API объект
        $exchangeName = $this->getDefaultExchange();

        if (empty($exchangeName)) {
            throw new EmptyNecessaryDotEnvKeyException('DEFAULT_EXCHANGE_FOR_TADE');
        }

        $setOrderData = [
            'exchange' => $exchangeName,
            'symbol' => $match[1] ?? null,
            'side' => $match[2] ?? null,
            'entry' => $entry,
            'leverage' => $match[5] ?? 10,
            'targets' => $targets,
            'stopLoss' => $sl[1] ?? null,
        ];

        if (!$this->checkIfAllNecessaryDataPresent($setOrderData)) {
            // todo придумать, что делать с сообщением, в котором отсутствует хоть один нужный элемент
        }

        // Создаём нужный объект через фабрику
        $exchange = ExchangeFactory::make($setOrderData['exchange']);

        if ($exchange === null) {
            throw new FactoryDidntCreateObjectException('ExchangeFactory', $setOrderData['exchange'].'Api');
        }

        // Отправлять данные в очередь для отправки данных в биржу
    }
}
