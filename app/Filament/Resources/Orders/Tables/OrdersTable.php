<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\Trading\OrderDirectionsEnum;
use App\Enums\Trading\OrderStatusesEnum;
use App\Enums\Trading\OrderTypesEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('exchange_order_id')
                    ->label('id Ордера на бирже')
                    ->searchable(),
                TextColumn::make('channel.name')
                    ->label('Ордер из канала')
                    ->searchable(),
                TextColumn::make('symbol')
                    ->label('Монета')
                    ->searchable(),
                TextColumn::make('direction')
                    ->label('Направление')
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->direction->label())
                    ->color(fn (string $state): string => match ($state) {
                        'Buy' => 'success',
                        'Sell' => 'danger',
                    })
                    ->numeric()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->type->label())
                    ->color(fn (string $state): string => match ($state) {
                        'Market' => 'primary',
                        'Limit' => 'info',
                    })
                    ->numeric()
                    ->sortable(),
                TextColumn::make('leverage')
                    ->label('Плечо')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('entry_price')
                    ->label('Цена входа')
                    ->money('USD')
                    ->sortable(),
                TextColumn::make('sl_price')
                    ->label('Цена SL')
                    ->money('USD')
                    ->sortable(),
                TextColumn::make('qty')
                    ->label('Количество монеты')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('remaining_qty')
                    ->label('Оставшееся количество')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->status->label())
                    ->color(fn (string $state): string => match ($state) {
                        'Open' => 'primary',
                        'Closed' => 'success',
                        'Cancelled' => 'danger',
                        'PartiallyClosed' => 'info',
                    })
                    ->numeric()
                    ->sortable(),
                TextColumn::make('opened_at')
                    ->label('Время открытия')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('closed_at')
                    ->label('Время закрытия')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('enter_balance')
                    ->label('Баланс на момент создания')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('pnl')
                    ->label('Текущие прибыль/убыток')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('pnl_percent')
                    ->label('Текущие прибыль/убыток %')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('commission')
                    ->label('Комиссия')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Время создания')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Время редактирования')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('lastExitOrder.type')
                    ->label('Тип последнего сработавшего target')
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->lastExitOrder->type->label())
                    ->color(fn (string $state): string => match ($state) {
                        'TP' => 'success',
                        'Manual' => 'warning',
                        'SL' => 'danger',
                    })
                    ->numeric()
                    ->sortable()
                    ->searchable(),
            ])
            ->filters([
                //
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
