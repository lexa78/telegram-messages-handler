<?php

declare(strict_types=1);

namespace App\Models\Trading;

use App\Models\AbstractModel;
use Illuminate\Support\Carbon;

/**
 * Таблица с информацией о каждом TP/SL которые принесли прибыль/убыток по проставленному ордеру
 *
 * @property int $id - идентификатор записи
 * @property int $order_id - Для какого ордера получили прибыль/убыток todo сделать связь
 * @property float $pnl - Сумма прибыли/убытка
 * @property float $pnl_percent - Процент прибыли/убытка
 * @property int $reason - Что закрыло сделку (TP/SL/Manual)
 * @property Carbon $created_at - время создания записи
 * @property Carbon $updated_at - время изменения записи
 *
 * @property-read - todo добавить описание связей
 */
class TradePnlLog extends AbstractModel
{
    protected $table = 'trade_pnl_logs';

    protected $fillable = [
        'order_id',
        'pnl',
        'pnl_percent',
        'reason',
        'created_at',
        'updated_at',
    ];
}
