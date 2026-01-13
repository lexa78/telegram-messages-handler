<?php

declare(strict_types=1);

namespace App\Services\WebsocketHandleJobs;

abstract class AbstractFuturesChannelsHandler
{
    abstract public function handle(array $data);
}
