<?php

declare(strict_types=1);

namespace App\Models\Trading;

use App\Models\AbstractModel;
use Illuminate\Support\Carbon;

/**
 * Таблица с информацией о целях ордера
 *
 * @property int $id - идентификатор записи
 * @property string $currency - Валюта баланса
 * @property float $sum - Сумма баланса
 * @property int $type - Тип баланса (Init, EndOfDay, etc)
 * @property int $exchange - Биржа, с которой берется баланс
 * @property Carbon $created_at - время создания записи
 * @property Carbon $updated_at - время изменения записи
 *
 * @property-read - todo добавить описание связей
 */
class Balance extends AbstractModel
{
    protected $table = 'order_targets';

    protected $fillable = [
        'currency',
        'sum',
        'type',
        'exchange',
        'created_at',
        'updated_at',
    ];
}
