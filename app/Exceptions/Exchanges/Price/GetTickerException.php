<?php

declare(strict_types=1);

namespace App\Exceptions\Exchanges\Price;

use App\Exceptions\Exchanges\AbstractExchangeException;
use Throwable;

/**
 * Ошибка при получении текущей цены пары
 */
class GetTickerException extends AbstractExchangeException
{
}
