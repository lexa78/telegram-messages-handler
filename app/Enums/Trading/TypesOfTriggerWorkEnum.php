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
}
