<?php

declare(strict_types=1);

namespace App\Models\Trading;

use App\Enums\Trading\TriggerTypesEnum;
use App\Enums\Trading\TypesOfTriggerWorkEnum;
use App\Models\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Таблица с информацией о целях ордера
 *
 * @property int $id - идентификатор записи
 * @property int $order_id - Для какого ордера эта точка
 * @property null|string $exchange_tp_id - id ордера, полученный от биржи
 * @property TriggerTypesEnum type - Тип точки выхода (TP/SL)
 * @property int price - По какой цене должен стработать
 * @property float qty - Какое количество будет убрано из ордера
 * @property TypesOfTriggerWorkEnum trigger_by - Каким образом сработает триггер (MarkPrice/LastPrice/SL)
 * @property bool is_triggered - Сработал ли триггер
 * @property null|Carbon triggered_at - Время срабатывания триггера
 * @property Carbon $created_at - время создания записи
 * @property Carbon $updated_at - время изменения записи
 *
 * @property-read Order $order - Ордер, для которого проставлен этот TP или SL
 */
class OrderTarget extends AbstractModel
{
    protected $table = 'order_targets';

    protected $fillable = [
        'order_id',
        'exchange_tp_id',
        'type',
        'price',
        'float qty',
        'trigger_by',
        'is_triggered',
        'triggered_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'type' => TriggerTypesEnum::class,
        'trigger_by' => TypesOfTriggerWorkEnum::class,
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
