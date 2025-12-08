<?php

declare(strict_types=1);

namespace App\Models\Trading;

use App\Models\AbstractModel;
use Illuminate\Support\Carbon;

/**
 * Таблица с информацией о целях ордера
 *
 * @property int $id - идентификатор записи
 * @property int $order_id - Для какого ордера эта точка todo сделать связь
 * @property null|string $exchange_tp_id - id ордера, полученный от биржи
 * @property int type - Тип точки выхода (TP/SL)
 * @property int price - По какой цене должен стработать
 * @property float qty - Какое количество будет убрано из ордера
 * @property int trigger_by - Каким образом сработает триггер (MarkPrice/LastPrice/SL)
 * @property bool is_triggered - Сработал ли триггер
 * @property null|Carbon triggered_at - Время срабатывания триггера
 * @property Carbon $created_at - время создания записи
 * @property Carbon $updated_at - время изменения записи
 *
 * @property-read - todo добавить описание связей
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
}
