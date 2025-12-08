<?php

declare(strict_types=1);

namespace App\Enums\Trading;

/**
 * Типы баланса, приведенные к int
 */
enum BalanceTypesEnum: int
{
    /** Начальная сумма на балансе */
    case Init = 1;

    /** Сумма на конец дня */
    case EndOfDay = 2;
}
