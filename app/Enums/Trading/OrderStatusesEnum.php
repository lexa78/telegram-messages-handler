<?php

declare(strict_types=1);

namespace App\Enums\Trading;

/**
 * Статусы ордера, приведенные к int
 */
enum OrderStatusesEnum: int
{
    case Open = 1;

    case PartiallyClosed = 2;

    case Closed = 3;

    case Cancelled = 4;

    public function label(): string
    {
        return match ($this->value) {
            1 => 'Open',
            2 => 'PartiallyClosed',
            3 => 'Closed',
            4 => 'Cancelled',
        };
    }
}
