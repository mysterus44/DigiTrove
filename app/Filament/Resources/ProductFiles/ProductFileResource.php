<?php

namespace App\Filament\Resources\ProductFiles;

use App\Filament\Resources\ProductFiles\Pages\CreateProductFile;
use App\Filament\Resources\ProductFiles\Pages\EditProductFile;
use App\Filament\Resources\ProductFiles\Pages\ListProductFiles;
use App\Filament\Resources\ProductFiles\Schemas\ProductFileForm;
use App\Filament\Resources\ProductFiles\Tables\ProductFilesTable;
use App\Models\ProductFile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductFileResource extends Resource
{
    protected static ?string $model = ProductFile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'original_name';

    public static function form(Schema $schema): Schema
    {
        return ProductFileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductFilesTable::configure($table);
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
            'index' => ListProductFiles::route('/'),
            'create' => CreateProductFile::route('/create'),
            'edit' => EditProductFile::route('/{record}/edit'),
        ];
    }
}
