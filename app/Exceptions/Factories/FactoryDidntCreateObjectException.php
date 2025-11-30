<?php

declare(strict_types=1);


namespace App\Exceptions\Factories;

use Exception;
use Throwable;

class FactoryDidntCreateObjectException extends Exception
{
    protected $code = 422;

    protected $message = 'The factory %s did not create an object of type %s.';

    public function __construct(string $factoryName, string $objectType, int $code = 0, ?Throwable $previous = null)
    {
        $this->message = sprintf($this->message, $factoryName, $objectType);
        if ($code !== 0) {
            $this->code = $code;
        }

        parent::__construct($this->message, $this->code, $previous);
    }
}
