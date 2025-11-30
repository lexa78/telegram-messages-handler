<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Patterns\Factories\JobFactory;
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
    public function handle(): void
    {
        $rabbitmqSettings = config('queue.rabbitmq');

        $this->info("Connecting to RabbitMQ...");

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

//        $channel->queue_declare($queueNames['raw'], false, true, false, false);

        $this->info('Waiting for messages in queue: '.$queueNames['raw']);

        $channel->basic_consume(
            $queueNames['raw'],
            '',
            false,
            true,
            false,
            false,
            function (AMQPMessage $msg) {
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

                $map = config('channels');

                if (!isset($map[$data['channelId']])) {
                    Log::channel('unhandledMessages')
                        ->error('The file config/channels.php don\'t contain class name for channelId = '.$data['channelId'],
                            [
                                'raw' => $msgBody,
                            ]);
                    return;
                }

                $className = $map[$data['channelId']];

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

//*****************************************************************
//        $msgBody = '{"channelTitle":"Binance Killers\u00ae VIP \ud83d\udd37","channelId":-1002513913321,"data":{"_":"updateNewChannelMessage","message":{"_":"message","out":false,"mentioned":false,"media_unread":false,"silent":false,"post":true,"from_scheduled":false,"legacy":false,"edit_hide":false,"pinned":false,"noforwards":false,"invert_media":false,"offline":false,"video_processing_pending":false,"paid_suggested_post_stars":false,"paid_suggested_post_ton":false,"id":3712,"peer_id":-1002513913321,"date":1764392475,"message":"\ud83d\udccdCoin : #ASTER\/USDT\n\n\ud83d\udfe2 LONG \n\n\u27a1\ufe0f Entry: 1.1000 - 1.0600\n\n\ud83c\udf10 Leverage: 20x\n\n\ud83c\udfaf Target 1: 1.1111\n\ud83c\udfaf Target 2: 1.1222\n\ud83c\udfaf Target 3: 1.1337\n\ud83c\udfaf Target 4: 1.1451\n\ud83c\udfaf Target 5: 1.1570\n\ud83c\udfaf Target 6: 1.1689\n\n\u274c StopLoss: 1.0400","entities":[{"_":"messageEntityCustomEmoji","offset":0,"length":2,"document_id":5391032818111363540},{"_":"messageEntityBold","offset":2,"length":7},{"_":"messageEntityHashtag","offset":9,"length":6},{"_":"messageEntityBold","offset":9,"length":6},{"_":"messageEntityBold","offset":15,"length":7},{"_":"messageEntityBold","offset":22,"length":2},{"_":"messageEntityCustomEmoji","offset":22,"length":2,"document_id":5215522595922779944},{"_":"messageEntityBold","offset":24,"length":8},{"_":"messageEntityBold","offset":32,"length":2},{"_":"messageEntityCustomEmoji","offset":32,"length":2,"document_id":5215330331711775720},{"_":"messageEntityBold","offset":34,"length":25},{"_":"messageEntityBold","offset":59,"length":2},{"_":"messageEntityCustomEmoji","offset":59,"length":2,"document_id":5364078230426890290},{"_":"messageEntityBold","offset":61,"length":16},{"_":"messageEntityBold","offset":77,"length":2},{"_":"messageEntityCustomEmoji","offset":77,"length":2,"document_id":5461009483314517035},{"_":"messageEntityBold","offset":79,"length":18},{"_":"messageEntityBold","offset":97,"length":2},{"_":"messageEntityCustomEmoji","offset":97,"length":2,"document_id":5461009483314517035},{"_":"messageEntityBold","offset":99,"length":18},{"_":"messageEntityBold","offset":117,"length":2},{"_":"messageEntityCustomEmoji","offset":117,"length":2,"document_id":5461009483314517035},{"_":"messageEntityBold","offset":119,"length":18},{"_":"messageEntityBold","offset":137,"length":2},{"_":"messageEntityCustomEmoji","offset":137,"length":2,"document_id":5461009483314517035},{"_":"messageEntityBold","offset":139,"length":18},{"_":"messageEntityBold","offset":157,"length":2},{"_":"messageEntityCustomEmoji","offset":157,"length":2,"document_id":5461009483314517035},{"_":"messageEntityBold","offset":159,"length":18},{"_":"messageEntityBold","offset":177,"length":2},{"_":"messageEntityCustomEmoji","offset":177,"length":2,"document_id":5461009483314517035},{"_":"messageEntityBold","offset":179,"length":19},{"_":"messageEntityBold","offset":198,"length":1},{"_":"messageEntityCustomEmoji","offset":198,"length":1,"document_id":5212992409213872592},{"_":"messageEntityBold","offset":199,"length":17}],"views":1,"forwards":0},"pts":5097,"pts_count":1}}';
//        $data = json_decode($msgBody, true);
//
//        $message = $data['data']['message']['message'] ?? 'fuck';
//
//        preg_match('/📍Coin\s*:\s*#(\S+).*?🟢\s*(\w+).*?➡️ Entry:\s*([\d.]+)\s*-\s*([\d.]+).*?🌐 Leverage:\s*(\d+)x.*?(🎯 Target.*)/s', $message, $match);
//
//        $coin = $match[1];
//        $direction = $match[2];
//        $entryFrom = $match[3];
//        $entryTo = $match[4];
//        $leverage = $match[5];
//
//        // Вытаскиваем все Targets
//        preg_match_all('/🎯 Target \d+:\s*([\d.]+)/', $match[6], $targets);
//
//        // Вытаскиваем StopLoss
//        preg_match('/❌ StopLoss:\s*([\d.]+)/', $message, $sl);
//
//        dd([
//            'coin' => $coin,
//            'direction' => $direction,
//            'entry' => [$entryFrom, $entryTo],
//            'leverage' => $leverage,
//            'targets' => $targets[1],
//            'stopLoss' => $sl[1],
//        ]);
//**************************************************************************
//        $path = storage_path('logs/unhandledMessages.log');
//        if (file_exists($path)) {
//            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); // массив строк
//            foreach ($lines as $line) {
//                $data = json_decode($line, true);
//                dd($data);
//            }
//            }


    }
}
