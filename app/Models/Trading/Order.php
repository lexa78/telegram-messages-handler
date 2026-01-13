<?php

declare(strict_types=1);

namespace App\Models\Trading;

use App\Enums\Trading\OrderDirectionsEnum;
use App\Enums\Trading\OrderStatusesEnum;
use App\Enums\Trading\OrderTypesEnum;
use App\Models\AbstractModel;
use App\Models\Channel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Таблица с информацией об ордерах
 *
 * @property int $id - идентификатор записи
 * @property string $exchange_order_id - id ордера, полученный от биржи
 * @property int $channel_id - Из какого канала пришёл сигнал
 * @property string $symbol - Какая пара в ордере
 * @property OrderDirectionsEnum $direction - Направление ордера Buy/Sell
 * @property OrderTypesEnum $type - Тип ордера Market/Limit
 * @property int $leverage - Размер плеча
 * @property float $entry_price - По какой цене вошли
 * @property null|float $sl_price - Цена стоп лосса
 * @property float $qty - Количество покупки
 * @property null|float $remaining_qty - Оставшееся количество после срабатывания TP
 * @property OrderStatusesEnum $status - Статус ордера (Open, PartiallyClosed, Closed, Cancelled)
 * @property Carbon $opened_at - Время открытия ордера
 * @property null|Carbon $closed_at - Время полного закрытия ордера
 * @property float $enter_balance - Баланс на момент входа
 * @property null|float $pnl - Прибыль/убыток ордера на момент закрытия. Пересчитывается каждый раз, когда срабатывает TP/SL
 * @property null|float $pnl_percent - Процент прибыли/убытка ордера на момент закрытия. Пересчитывается каждый раз, когда срабатывает TP/SL
 * @property null|int $last_exit_order_id - id последнего сработавшего ордера
 * @property null|float $commission - Коммиссия сделки
 * @property Carbon $created_at - время создания записи
 * @property Carbon $updated_at - время изменения записи
 *
 * @property-read Channel $channel - Канал, из сообщения которого был проставлен этот ордер
 * @property-read OrderTarget $lastExitOrder - Последний сработавший ордер TP или SL
 * @property-read Collection<OrderTarget> $targetOrders - Все ордера TP и SL для этого ордера
 * @property-read Collection<TradePnlLog> $tradingLogs - Логи изменения Pnl после срабатывания TP или SL для этого ордера
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

    protected $casts = [
        'status' => OrderStatusesEnum::class,
        'direction' => OrderDirectionsEnum::class,
        'type' => OrderTypesEnum::class,
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    public function lastExitOrder(): BelongsTo
    {
        return $this->belongsTo(OrderTarget::class, 'last_exit_order_id');
    }

    public function targetOrders(): HasMany
    {
        return $this->hasMany(OrderTarget::class, 'order_id');
    }

    public function tradingLogs(): HasMany
    {
        return $this->hasMany(TradePnlLog::class, 'order_id');
    }
}
