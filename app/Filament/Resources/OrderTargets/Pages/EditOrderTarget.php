<?php

namespace App\Filament\Resources\OrderTargets\Pages;

use App\Filament\Resources\OrderTargets\OrderTargetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOrderTarget extends EditRecord
{
    protected static string $resource = OrderTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
