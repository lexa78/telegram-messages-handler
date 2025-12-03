<?php

declare(strict_types=1);

namespace App\Exceptions\Exchanges;

use Exception;
use Throwable;

class AbstractExchangeException extends Exception
{
    public const int EXCEPTION_CODE_BY_DEFAULT = 422;

    public array $context = [];

    public function __construct(
        $message,
        $code = self::EXCEPTION_CODE_BY_DEFAULT,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        $this->message = $message;
        $this->code = $code;
        $this->context = $context;
        parent::__construct($this->message, $this->code, $previous);
    }

    public function context(): array
    {
        return $this->context;
    }
}
