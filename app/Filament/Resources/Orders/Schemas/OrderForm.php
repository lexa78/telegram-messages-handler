<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\Trading\OrderStatusesEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('exchange_order_id')
                    ->required(),
                Select::make('channel_id')
                    ->relationship('channel', 'name')
                    ->required(),
                TextInput::make('symbol')
                    ->required(),
                TextInput::make('direction')
                    ->required()
                    ->numeric(),
                TextInput::make('type')
                    ->required()
                    ->numeric(),
                TextInput::make('leverage')
                    ->required()
                    ->numeric(),
                TextInput::make('entry_price')
                    ->required()
                    ->numeric()
                    ->prefix('$'),
                TextInput::make('sl_price')
                    ->numeric()
                    ->prefix('$'),
                TextInput::make('qty')
                    ->required()
                    ->numeric(),
                TextInput::make('remaining_qty')
                    ->numeric(),
                Select::make('status')
                    ->options(OrderStatusesEnum::class)
                    ->required(),
                DateTimePicker::make('opened_at')
                    ->required(),
                DateTimePicker::make('closed_at'),
                TextInput::make('enter_balance')
                    ->required()
                    ->numeric(),
                TextInput::make('pnl')
                    ->numeric(),
                TextInput::make('pnl_percent')
                    ->numeric(),
                TextInput::make('commission')
                    ->numeric(),
                Select::make('last_exit_order_id')
                    ->relationship('lastExitOrder', 'id'),
            ]);
    }
}
