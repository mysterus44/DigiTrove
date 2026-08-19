<?php

namespace App\Filament\Resources\Redirects\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RedirectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('from_path')->label('Ancienne URL')->searchable()->sortable(),
                TextColumn::make('to_path')->label('Nouvelle URL')->searchable(),
                TextColumn::make('status_code')->label('Code')->badge(),
                TextColumn::make('created_at')->label('Créée le')->dateTime('d/m/Y'),
            ])
            ->defaultSort('from_path')
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
