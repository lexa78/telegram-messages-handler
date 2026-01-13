<?php

declare(strict_types=1);

namespace App\Enums\Trading;

/**
 * Типы триггера, приведенные к int
 */
enum TriggerTypesEnum: int
{
    case TP = 1;

    case Manual = 2;

    case SL = 3;

    public function label(): string
    {
        return match ($this->value) {
            1 => 'TP',
            2 => 'Manual',
            3 => 'SL',
        };
    }

}
