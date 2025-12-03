<?php

declare(strict_types=1);

namespace App\Exceptions\Exchanges\Price;

use App\Exceptions\Exchanges\AbstractExchangeException;
use Throwable;

/**
 * Ошибка при получении ключа lotSizeFilter из ответа от биржи
 */
class GetLotSizeFilterFromExchangeResponseException extends AbstractExchangeException
{
}
