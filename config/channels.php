<?php

declare(strict_types=1);

use App\Jobs\BkvChannelJob;

/*
 * Map для привязки обработчика сообщений к id telegram канала
 */
return [
    // добавляем новые каналы по мере необходимости
    '-1002513913321' => BkvChannelJob::class,
];
