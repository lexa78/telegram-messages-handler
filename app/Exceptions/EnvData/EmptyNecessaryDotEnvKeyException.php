<?php

declare(strict_types=1);

namespace App\Exceptions\EnvData;

use Exception;
use Throwable;

class EmptyNecessaryDotEnvKeyException extends Exception
{
    protected $code = 422;

    protected $message = 'The environment variable "%s" is missing.';

    public function __construct(string $variableName, int $code = 0, ?Throwable $previous = null)
    {
        $this->message = sprintf($this->message, $variableName);
        if ($code !== 0) {
            $this->code = $code;
        }

        parent::__construct($this->message, $this->code, $previous);
    }
}
