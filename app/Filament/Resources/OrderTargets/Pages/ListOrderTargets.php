<?php

namespace App\Filament\Resources\OrderTargets\Pages;

use App\Filament\Resources\OrderTargets\OrderTargetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOrderTargets extends ListRecords
{
    protected static string $resource = OrderTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
