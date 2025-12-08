<?php

declare(strict_types=1);

namespace App\Exceptions\Exchanges\Traiding;

use App\Exceptions\Exchanges\AbstractExchangeException;
use Throwable;

/**
 * Ошибка при получении текущего баланса
 */
class GetCurrentBalanceException extends AbstractExchangeException
{
}
