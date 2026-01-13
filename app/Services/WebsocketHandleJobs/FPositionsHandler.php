<?php

declare(strict_types=1);


namespace App\Services\WebsocketHandleJobs;

use Illuminate\Support\Facades\Log;

class FPositionsHandler extends AbstractFuturesChannelsHandler
{
    public function handle(array $data)
    {
        // пока заглушка
        Log::channel('websocketUnhandledMessages')
            ->error('Message from FPositionsHandler', [
                'message' => $data,
            ]);
    }
}
