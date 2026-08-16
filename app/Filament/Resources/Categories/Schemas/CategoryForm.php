<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Models\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nom')
                    ->required()
                    ->maxLength(120)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state): mixed => $set('slug', Str::slug($state ?? ''))),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(120)
                    ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/')
                    ->unique(Category::class, 'slug', ignoreRecord: true),
                Select::make('parent_id')
                    ->label('Categorie parente')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload(),
                TextInput::make('position')
                    ->integer()
                    ->default(0)
                    ->required(),
            ]);
    }
}
