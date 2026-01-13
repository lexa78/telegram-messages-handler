<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Channel;
use App\Patterns\Factories\JobFactory;
use App\Services\Channels\ChannelService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class TelegramConsume extends Command
{
    protected $signature = 'telegram:consume';

    protected $description = 'Consume queue messages from bot to RabbitMQ';

    /**
     * @throws Exception
     */
    public function handle(ChannelService $channelService): void
    {
        $channelsInfo = $channelService->getAllCached();

        $rabbitmqSettings = config('queue.connections.rabbitmq');

        $this->info('Connecting to telegram RabbitMQ...' . '['. now()->format('Y-m-d H:i:s').']');

        $connection = new AMQPStreamConnection(
            $rabbitmqSettings['host'],
            (int) $rabbitmqSettings['port'],
            $rabbitmqSettings['login'],
            $rabbitmqSettings['password'],
        );

        $queueNames = config('queueNames');
        if (empty($queueNames['raw'])) {
            Log::error('The file config/queueNames.php don\'t contain queue name for raw messages.');
            return;
        }

        $channel = $connection->channel();

        $this->info('Waiting for messages in queue: '.$queueNames['raw'] . '['. now()->format('Y-m-d H:i:s').']');

        $channelsMap = config('channels');

        $channel->basic_consume(
            $queueNames['raw'],
            '',
            false,
            true,
            false,
            false,
            function (AMQPMessage $msg) use($channelService, $channelsInfo, $channelsMap, $queueNames): void {
                $msgBody = $msg->getBody();
                $data = json_decode($msgBody, true);

                // Если сообщение битое — логируем и продолжаем работу
                if (empty($data)) {
                    Log::channel('unhandledMessages')
                        ->error('Failed to decode telegram message', [
                            'raw' => $msgBody,
                        ]);
                    return;
                }

                if (empty($data['channelId'])) {
                    Log::channel('unhandledMessages')
                        ->error('Message key "channelId" is missing in telegram message', [
                            'raw' => $msgBody,
                        ]);
                    return;
                }

                /** @var Channel $channel */
                $channel = $channelsInfo->get($data['channelId']);
                // если канала нет в кэше, автоматически, его нет в БД, сохраняем и там и там
                if ($channel === null) {
                    $channelsInfo = $channelService->findOrCreate((string) $data['channelId'], $data['channelTitle']);
                    $channel = $channelsInfo->get($data['channelId']);
                }

                // если канал не помечен, что в нем может быть нужная информация, то пропускаем сообщение
                // чтобы пометить канал, надо в админке кликнуть по чекбоксу
                if (!$channel->is_for_handle) {
                    return;
                }

                // если канал помечен, но его Job не найдена, надо будет разбираться по логам
                if (!isset($channelsMap[$channel->cid])) {
                    Log::channel('unhandledMessages')
                        ->error('The file config/channels.php don\'t contain class name for channelId = '.$channel->cid,
                            [
                                'raw' => $msgBody,
                            ]);
                    return;
                }

                $className = $channelsMap[$channel->cid];
                $data['channelId'] = $channel->getKey();

                $channelJob = JobFactory::make($className, $data);

                if (empty($queueNames['processed'])) {
                    Log::error('The file config/queueNames.php don\'t contain queue name for laravel workers.');
                    return;
                }

                // Отправляем в Laravel очередь
                dispatch($channelJob)->onQueue($queueNames['processed']);
            }
        );

        while ($channel->is_open()) {
            $channel->wait();
        }
    }
}
