<?php

declare(strict_types=1);

namespace App\Repositories\Trading;

use App\Models\Channel;
use App\Models\Trading\Order;
use App\Models\Trading\OrderTarget;
use App\Repositories\AbstractRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Работа с таблицей order_targets
 */
class OrderTargetRepository extends AbstractRepository
{
    public function insert(array $data): void
    {
        OrderTarget::query()->insert($data);
    }

    public function getByExchangeTpId(int $id, array $relations = []): ?OrderTarget
    {
        $query = OrderTarget::query()->where('exchange_tp_id', $id);
        if ($relations !== []) {
            $query->with($relations);
        }

        return $query->first();
    }
}
