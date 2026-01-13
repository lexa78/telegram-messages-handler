<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Trading\Order;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Таблица с информацией о каналах
 *
 * @property int $id - идентификатор записи
 * @property string $cid - id канала в telegram
 * @property string $name - имя канала в telegram
 * @property bool $is_for_handle - признак, показывающий должны ли обрабатываться сообщения из этого канала
 * @property Carbon $created_at - время создания записи
 * @property Carbon $updated_at - время изменения записи
 *
 * @property-read Collection<Order> $orders - проставленные ордера из сообщений этого канала
 */
class Channel extends AbstractModel
{
    protected $table = 'channels';

    protected $fillable = [
        'cid',
        'name',
        'is_for_handle',
        'created_at',
        'updated_at',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'channel_id');
    }
}
