<?php

namespace App\Filament\Resources\ProductFiles\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductFilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')->label('Produit')->searchable()->sortable(),
                TextColumn::make('original_name')->label('Fichier')->searchable(),
                TextColumn::make('version'),
                TextColumn::make('size_bytes')->label('Taille')->formatStateUsing(fn (int $state): string => number_format($state / 1024 / 1024, 2, ',', ' ').' Mo'),
                IconColumn::make('is_active')->label('Actif')->boolean(),
                TextColumn::make('created_at')->label('Ajoute le')->dateTime()->sortable(),
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
