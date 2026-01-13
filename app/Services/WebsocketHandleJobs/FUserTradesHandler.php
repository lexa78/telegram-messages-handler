<?php

declare(strict_types=1);


namespace App\Services\WebsocketHandleJobs;

use App\Enums\Trading\OrderStatusesEnum;
use App\Enums\Trading\TriggerTypesEnum;
use App\Models\Trading\TradePnlLog;
use App\Repositories\Trading\OrderTargetRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class FUserTradesHandler extends AbstractFuturesChannelsHandler
{
    public function __construct(
        private readonly OrderTargetRepository $orderTargetRepository,
    ) {

    }

    public function handle(array $data): void
    {
        $result = head($data['result']);
        $target = $this->orderTargetRepository->getByExchangeTpId((int) $result['order_id'], ['order']);
        if ($target === null) {
            Log::channel('websocketUnhandledMessages')
                ->error('Message from FUserTradesHandler. Не найдена запись для target order с id = '.$result['order_id'],
                    [
                        'message' => $data,
                    ],
                );

            return;
        }
        $target->is_triggered = true;
        $target->triggered_at = Carbon::createFromTimestamp($result['create_time']);
        $target->save();

        $order = $target->order;
        // подсчет PnL
        // Для LONG PnL = (exit_price - entry_price) * size - fee
        // Для SHORT PnL = (entry_price - exit_price) * size - fee
        $resultSize = (int) $result['size'];
        $commission = (float) $result['fee'];
        if ($target->type === TriggerTypesEnum::TP) {
            $different = (float) $result['price'] - $order->entry_price;
            if ((int) $order->qty === $resultSize) {
                $orderStatus = OrderStatusesEnum::Closed;
            } else {
                $orderStatus = OrderStatusesEnum::PartiallyClosed;
            }
        } else {
            $different = $order->entry_price - (float) $result['price'];
            $orderStatus = OrderStatusesEnum::Closed;
        }
        $pnl = $different * $resultSize - $commission;

        // подсчет процента Pnl
        // %PnL = net_pnl / margin_used × 100
        // margin_used = (entry_price × size) / leverage
        $marginUsed = $order->entry_price * $resultSize / $order->leverage;
        $pnlPercent = $pnl / $marginUsed * 100;

        $tradeLog = new TradePnlLog();
        $tradeLog->pnl = $pnl;
        $tradeLog->pnl_percent = $pnlPercent;
        $tradeLog->reason = $target->type->value;
        $tradeLog->order()->associate($order);
        $tradeLog->save();

        $order->remaining_qty -= $resultSize;
        $order->status = $orderStatus->value;
        if ($orderStatus === OrderStatusesEnum::Closed) {
            $order->closed_at = now();
        }
        $orderPnl = $order->pnl ?? 0;
        $order->pnl = $orderPnl + $pnl;
        $orderPnlPercent = $order->pnl_percent ?? 0;
        $order->pnl_percent = $orderPnlPercent + $pnlPercent;
        $orderCommission = $order->commission ?? 0;
        $order->commission = $orderCommission + $commission;
        $order->lastExitOrder()->associate($target);
        $order->save();
    }
}
