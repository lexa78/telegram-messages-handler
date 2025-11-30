<?php

declare(strict_types=1);

/*
 * Имена очередей, чтобы случайно не создать/слушать очередь с таким же наименованием
 */
return [
    'raw' => 'telegram.raw',
    'processed' => 'laravel.jobs',
];
