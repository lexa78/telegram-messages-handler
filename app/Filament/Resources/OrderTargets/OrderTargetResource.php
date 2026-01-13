<?php

namespace App\Filament\Resources\OrderTargets;

use App\Filament\Resources\OrderTargets\Pages\CreateOrderTarget;
use App\Filament\Resources\OrderTargets\Pages\EditOrderTarget;
use App\Filament\Resources\OrderTargets\Pages\ListOrderTargets;
use App\Filament\Resources\OrderTargets\Schemas\OrderTargetForm;
use App\Filament\Resources\OrderTargets\Tables\OrderTargetsTable;
use App\Models\Trading\OrderTarget;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OrderTargetResource extends Resource
{
    protected static ?string $model = OrderTarget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'exchange_tp_id';

    public static function form(Schema $schema): Schema
    {
        return OrderTargetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrderTargetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrderTargets::route('/'),
            'create' => CreateOrderTarget::route('/create'),
            'edit' => EditOrderTarget::route('/{record}/edit'),
        ];
    }
}
