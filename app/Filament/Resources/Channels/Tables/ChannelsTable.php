<?php

namespace App\Filament\Resources\Channels\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ChannelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cid')
                    ->label('id канала в телеге')
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Наименование канала')
                    ->searchable(),
                IconColumn::make('is_for_handle')
                    ->label('Этот канал с сигналами')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Дата создания')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Дата редактирования')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_pnl')
                    ->label('Прибыль/убыток за все время')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('today_pnl')
                    ->label('Прибыль/убыток за сегодня')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_for_handle')
                    ->label('Этот канал с сигналами?'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
