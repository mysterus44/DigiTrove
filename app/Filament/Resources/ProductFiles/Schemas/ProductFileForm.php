<?php

namespace App\Filament\Resources\ProductFiles\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductFileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Produit')
                    ->relationship('product', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->disabledOn('edit'),
                FileUpload::make('uploaded_file')
                    ->label('Livrable prive')
                    ->disk('private')
                    ->directory('products')
                    ->visibility('private')
                    ->storeFileNamesIn('uploaded_original_name')
                    ->required()
                    ->maxSize(2_097_152)
                    ->visibleOn('create'),
                Hidden::make('storage_disk')->default('private'),
                Hidden::make('uploaded_original_name')->visibleOn('create'),
                TextInput::make('original_name')
                    ->label('Nom affiche')
                    ->required()
                    ->maxLength(255)
                    ->visibleOn('edit'),
                TextInput::make('version')
                    ->required()
                    ->default('1.0')
                    ->maxLength(40)
                    ->disabledOn('edit'),
                TextInput::make('position')
                    ->integer()
                    ->default(0)
                    ->required(),
                Toggle::make('is_active')
                    ->label('Fichier actif')
                    ->default(true)
                    ->required(),
            ]);
    }
}
