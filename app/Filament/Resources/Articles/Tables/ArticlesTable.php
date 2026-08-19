<?php

namespace App\Filament\Resources\Articles\Tables;

use App\Enums\ArticleStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ArticlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Titre')->searchable()->sortable()->limit(60),
                TextColumn::make('slug')->searchable()->limit(40),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (ArticleStatus $state): string => $state->label()),
                TextColumn::make('category.name')->label('Catégorie'),
                TextColumn::make('products_count')->label('Produits liés')->counts('products'),
                TextColumn::make('reading_minutes')->label('Lecture')->suffix(' min'),
                TextColumn::make('published_at')->label('Publié le')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->defaultSort('published_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options(collect(ArticleStatus::cases())
                        ->mapWithKeys(fn (ArticleStatus $case): array => [$case->value => $case->label()])
                        ->all()),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
