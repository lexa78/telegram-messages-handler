<?php

declare(strict_types=1);

use App\Jobs\BkvChannelJob;
use App\Jobs\SkrChannelJob;
use App\Jobs\SvrChannelJob;

/*
 * Map для привязки обработчика сообщений к id telegram канала
 */
return [
    // добавляем новые каналы по мере необходимости
    '-1002513913321' => BkvChannelJob::class,
    '-1001732065792' => SkrChannelJob::class,
    '-1001832087544' => SvrChannelJob::class,
];
