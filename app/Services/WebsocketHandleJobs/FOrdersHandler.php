<?php

declare(strict_types=1);


namespace App\Services\WebsocketHandleJobs;

use Illuminate\Support\Facades\Log;

class FOrdersHandler extends AbstractFuturesChannelsHandler
{
    public function handle(array $data)
    {
        // пока заглушка
        Log::channel('websocketUnhandledMessages')
            ->error('Message from FOrdersHandler', [
                'message' => $data,
            ]);
    }
}
