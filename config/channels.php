<?php

declare(strict_types=1);

use App\Jobs\BbChannelJob;
use App\Jobs\BkvChannelJob;
use App\Jobs\CntChannelJob;
use App\Jobs\CvcChannelJob;
use App\Jobs\EskChannelJob;
use App\Jobs\EtChannelJob;
use App\Jobs\EtgChannelJob;
use App\Jobs\KsChannelJob;
use App\Jobs\SkrChannelJob;
use App\Jobs\SvrChannelJob;
use App\Jobs\WotChannelJob;
use App\Jobs\WqoChannelJob;

/*
 * Map для привязки обработчика сообщений к id telegram канала
 */
return [
    // добавляем новые каналы по мере необходимости
    '-1002513913321' => BkvChannelJob::class,
    '-1001732065792' => SkrChannelJob::class,
    '-1001832087544' => SvrChannelJob::class,
    '-1001347728413' => WqoChannelJob::class,
    '-1003114702207' => EtChannelJob::class,
    '-1001573488012' => CntChannelJob::class,
    '-1001309612050' => WotChannelJob::class,
    '-1003127396427' => KsChannelJob::class,
    '-1002529586843' => EtgChannelJob::class,
    '-1001351499165' => BbChannelJob::class,
    '-1001763937690' => CvcChannelJob::class,
    '-1002309206103' => EskChannelJob::class,
];
