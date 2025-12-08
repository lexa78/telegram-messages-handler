<?php

declare(strict_types=1);

namespace App\Enums\Trading;

/**
 * Тип ордера, приведенные к int
 */
enum OrderTypesEnum: int
{
    case Market = 1;

    case Limit = 2;
}
