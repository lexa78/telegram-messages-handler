<?php

declare(strict_types=1);

namespace App\Enums\Trading;

/**
 * Наименования бирж, приведенные к int
 */
enum ExchangesEnum: int
{
    case Bybit = 1;
    case Gate = 2;
}
