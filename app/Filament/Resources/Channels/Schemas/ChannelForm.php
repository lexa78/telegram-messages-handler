<?php

namespace App\Filament\Resources\Channels\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ChannelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('cid')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                Toggle::make('is_for_handle')
                    ->required(),
                TextInput::make('total_pnl')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('today_pnl')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
