<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WebsocketHandleJobs\FOrdersHandler;
use App\Services\WebsocketHandleJobs\FPositionsHandler;
use App\Services\WebsocketHandleJobs\FUserTradesHandler;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class WebsocketConsume extends Command
{
    protected $signature = 'websocket:consume';

    protected $description = 'Consume queue messages from websocket';

    /**
     * @throws Exception
     */
    public function handle(
        FPositionsHandler $positionsHandler,
        FOrdersHandler $ordersHandler,
        FUserTradesHandler $userTradesHandler
    ): void {
        $channelHandlerMap = [
            'futures.orders' => $ordersHandler,
            'futures.usertrades' => $userTradesHandler,
            'futures.positions' => $positionsHandler,
        ];

        $rabbitmqSettings = config('queue.connections.rabbitmq');

        $this->info('Connecting to websocket RabbitMQ...'.'['.now()->format('Y-m-d H:i:s').']');

        $connection = new AMQPStreamConnection(
            $rabbitmqSettings['host'],
            (int) $rabbitmqSettings['port'],
            $rabbitmqSettings['login'],
            $rabbitmqSettings['password'],
        );

        $queueNames = config('queueNames');
        if (empty($queueNames['gateWebsocket'])) {
            Log::error('The file config/queueNames.php don\'t contain queue name for websocket messages.');
            return;
        }

        $channel = $connection->channel();

        $this->info('Waiting for messages in queue: '.$queueNames['gateWebsocket'].'['.now()->format('Y-m-d H:i:s').']');

        $channel->basic_consume(
            $queueNames['gateWebsocket'],
            '',
            false,
            true,
            false,
            false,
            function (AMQPMessage $msg) use($channelHandlerMap): void {
                $msgBody = $msg->getBody();
                $data = json_decode($msgBody, true);

                // Если сообщение битое — логируем и продолжаем работу
                if (empty($data)) {
                    Log::channel('websocketUnhandledMessages')
                        ->error('Failed to decode telegram message', [
                            'raw' => $msgBody,
                        ]);
                    return;
                }

                if (!isset($data['channel'])) {
                    Log::channel('websocketUnhandledMessages')
                        ->error('Key channel is missing in websocket messages.', [
                            'raw' => $msgBody,
                        ]);
                    return;
                }

                $handler = $channelHandlerMap[$data['channel']] ?? null;
                if ($handler === null) {
                    return;
                }

                $handler->handle($data);
            }
        );

        while ($channel->is_open()) {
            $channel->wait();
        }
    }
}
