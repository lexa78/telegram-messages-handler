<?php

namespace App\Filament\Resources\OrderTargets\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrderTargetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order.symbol')
                    ->label('Target для монеты')
                    ->searchable(),
                TextColumn::make('exchange_tp_id')
                    ->label('id Target на бирже')
                    ->searchable(),
                TextColumn::make('type')
                    ->label('Тип target')
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->type->label())
                    ->color(fn (string $state): string => match ($state) {
                        'TP' => 'success',
                        'Manual' => 'warning',
                        'SL' => 'danger',
                    })
                    ->numeric()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('price')
                    ->label('Предположительная цена срабатывания')
                    ->money('USD')
                    ->sortable(),
                TextColumn::make('qty')
                    ->label('Количество закрытия')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('trigger_by')
                    ->label('Каким образом сработает триггер')
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->trigger_by->label())
                    ->color(fn (string $state): string => match ($state) {
                        'MarkPrice' => 'primary',
                        'LastPrice' => 'info',
                    })
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_triggered')
                    ->label('Сработал?')
                    ->boolean(),
                TextColumn::make('triggered_at')
                    ->label('Дата срабатывания')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Дата создания')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Дата изменения')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
