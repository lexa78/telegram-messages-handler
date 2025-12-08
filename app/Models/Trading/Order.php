<?php

declare(strict_types=1);

namespace App\Models\Trading;

use App\Models\AbstractModel;
use Illuminate\Support\Carbon;

/**
 * Таблица с информацией об ордерах
 *
 * @property int $id - идентификатор записи
 * @property string $exchange_order_id - id ордера, полученный от биржи
 * @property int $channel_id - Из какого канала пришёл сигнал todo сделать связь
 * @property string $symbol - Какая пара в ордере
 * @property int $direction - Направление ордера Buy/Sell
 * @property int $type - Тип ордера Market/Limit
 * @property int $leverage - Размер плеча
 * @property float $entry_price - По какой цене вошли
 * @property null|float $sl_price - Цена стоп лосса
 * @property float $qty - Количество покупки
 * @property null|float $remaining_qty - Оставшееся количество после срабатывания TP
 * @property int $status - Статус ордера (Open, PartiallyClosed, Closed, Cancelled)
 * @property Carbon $opened_at - Время открытия ордера
 * @property null|Carbon $closed_at - Время полного закрытия ордера
 * @property float $enter_balance - Баланс на момент входа
 * @property null|float $pnl - Прибыль/убыток ордера на момент закрытия. Пересчитывается каждый раз, когда срабатывает TP/SL
 * @property null|float $pnl_percent - Процент прибыли/убытка ордера на момент закрытия. Пересчитывается каждый раз, когда срабатывает TP/SL
 * @property null|int $last_exit_order_id - id последнего сработавшего ордера todo добавить сввязь
 * @property null|float $commission - Коммиссия сделки
 * @property Carbon $created_at - время создания записи
 * @property Carbon $updated_at - время изменения записи
 *
 * @property-read - todo добавить описание связей
 */
class Order extends AbstractModel
{
    protected $table = 'orders';

    protected $fillable = [
        'exchange_order_id',
        'channel_id',
        'symbol',
        'direction',
        'type',
        'leverage',
        'entry_price',
        'sl_price',
        'qty',
        'remaining_qty',
        'status',
        'opened_at',
        'closed_at',
        'enter_balance',
        'pnl',
        'pnl_percent',
        'last_exit_order_id',
        'commission',
        'created_at',
        'updated_at',
    ];
}
