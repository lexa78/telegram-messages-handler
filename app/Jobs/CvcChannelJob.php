<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Patterns\Factories\ExchangeFactory;
use Illuminate\Support\Facades\Log;

/**
 * Обработка данных из канала Cvc
 */
class CvcChannelJob extends AbstractChannelJob
{
    /**
     * todo нужно будет что-то думать с обработками картинок и сопоставлять их с обработанным текстом
     */
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

    public function handle(): void
    {
    }
}
