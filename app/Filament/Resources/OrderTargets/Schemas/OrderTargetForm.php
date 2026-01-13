<?php

namespace App\Filament\Resources\OrderTargets\Schemas;

use App\Enums\Trading\TriggerTypesEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class OrderTargetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('order_id')
                    ->relationship('order', 'id')
                    ->required(),
                TextInput::make('exchange_tp_id'),
                Select::make('type')
                    ->options(TriggerTypesEnum::class)
                    ->required(),
                TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->prefix('$'),
                TextInput::make('qty')
                    ->required()
                    ->numeric(),
                TextInput::make('trigger_by')
                    ->required()
                    ->numeric(),
                Toggle::make('is_triggered')
                    ->required(),
                DateTimePicker::make('triggered_at'),
            ]);
    }
}
