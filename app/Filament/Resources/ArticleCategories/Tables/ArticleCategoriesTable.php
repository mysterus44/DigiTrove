<?php

namespace App\Filament\Resources\ArticleCategories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ArticleCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Catégorie')->searchable()->sortable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('articles_count')->label('Articles')->counts('articles'),
                TextColumn::make('position')->sortable(),
            ])
            ->defaultSort('position')
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
