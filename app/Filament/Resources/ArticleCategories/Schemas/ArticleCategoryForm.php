<?php

namespace App\Filament\Resources\ArticleCategories\Schemas;

use App\Models\ArticleCategory;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ArticleCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
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
                ->unique(ArticleCategory::class, 'slug', ignoreRecord: true),
            TextInput::make('position')->integer()->default(0)->required(),
        ]);
    }
}
