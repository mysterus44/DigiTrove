<?php

namespace App\Filament\Resources\ProductFiles\Pages;

use App\Filament\Resources\ProductFiles\ProductFileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductFile extends EditRecord
{
    protected static string $resource = ProductFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
