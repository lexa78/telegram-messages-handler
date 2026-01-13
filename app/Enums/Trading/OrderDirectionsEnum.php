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

    public function label(): string
    {
        return match ($this->value) {
            1 => 'Buy',
            2 => 'Sell',
        };
    }
}
