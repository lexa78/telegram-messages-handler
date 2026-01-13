<?php

declare(strict_types=1);

namespace App\Enums\Trading;

/**
 * Способы срабатывания триггера, приведенные к int
 */
enum TypesOfTriggerWorkEnum: int
{
    case MarkPrice = 1;

    case LastPrice = 2;

    public function label(): string
    {
        return match ($this->value) {
            1 => 'MarkPrice',
            2 => 'LastPrice',
        };
    }
}
