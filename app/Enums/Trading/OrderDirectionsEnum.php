<?php

declare(strict_types=1);

namespace App\Enums\Trading;

/**
 * Направления ордера, приведенные к int
 */
enum OrderDirectionsEnum: int
{
    case Buy = 1;

    case Sell = 2;
}
