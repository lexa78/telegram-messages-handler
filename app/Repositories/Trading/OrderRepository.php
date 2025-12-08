<?php

declare(strict_types=1);

namespace App\Repositories\Trading;

use App\Models\Channel;
use App\Models\Trading\Order;
use App\Repositories\AbstractRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Работа с таблицей orders
 */
class OrderRepository extends AbstractRepository
{
    public function insertGetId(array $data): int
    {
        return DB::table('orders')->insertGetId($data);
    }
}
