<?php

namespace App\Filament\Resources\Articles\Schemas;

use App\Enums\ArticleStatus;
use App\Models\Article;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label('Titre')
                    ->required()
                    ->maxLength(200)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                        // The slug is suggested only while it is still empty. Rewriting it
                        // on every title edit would silently break the URL of a published
                        // article — and every backlink pointing at it.
                        if (blank($get('slug'))) {
                            $set('slug', Str::slug($state ?? ''));
                        }
                    }),

                TextInput::make('slug')
                    ->required()
                    ->maxLength(200)
                    ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/')
                    ->unique(Article::class, 'slug', ignoreRecord: true)
                    ->helperText('Immuable une fois publié : le changer casse les liens entrants. Créez plutôt une redirection.'),

                Select::make('status')
                    ->label('Statut')
                    ->options(collect(ArticleStatus::cases())
                        ->mapWithKeys(fn (ArticleStatus $case): array => [$case->value => $case->label()])
                        ->all())
                    ->default(ArticleStatus::Draft->value)
                    ->required()
                    ->live(),

                DateTimePicker::make('published_at')
                    ->label('Publié le')
                    // The database refuses `published` without a date; asking for it here
                    // turns that refusal into a form message instead of a 500.
                    ->required(fn (Get $get): bool => $get('status') === ArticleStatus::Published->value)
                    ->helperText('Une date future programme la parution : l’article reste invisible jusque-là.'),

                Select::make('article_category_id')
                    ->label('Catégorie')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),

                Select::make('author_id')
                    ->label('Auteur (compte)')
                    ->relationship('author', 'email')
                    ->searchable()
                    ->preload(),

                TextInput::make('author_name')
                    ->label('Signature affichée')
                    ->maxLength(120)
                    ->helperText('Snapshot : la signature survit à la suppression du compte.'),

                Textarea::make('excerpt')
                    ->label('Chapô')
                    ->rows(3)
                    ->maxLength(400),

                Textarea::make('body')
                    ->label('Contenu (Markdown)')
                    ->rows(20)
                    ->required()
                    ->columnSpanFull()
                    // Markdown, never HTML. The render strips every raw tag, so pasting
                    // `<script>` here produces nothing on the page rather than a stored XSS.
                    ->helperText('Markdown. Le HTML brut est retiré au rendu — collez du Markdown, pas du HTML.'),

                TextInput::make('cover_image_url')
                    ->label('Image de couverture (URL)')
                    ->url()
                    ->maxLength(500),

                TextInput::make('meta_title')
                    ->label('Meta title')
                    ->maxLength(60)
                    ->helperText('60 caractères maximum — au-delà, Google tronque.'),

                Textarea::make('meta_description')
                    ->label('Meta description')
                    ->rows(2)
                    ->maxLength(160)
                    ->helperText('160 caractères maximum.'),

                TextInput::make('canonical_url')
                    ->label('Canonical personnalisée')
                    ->url()
                    ->maxLength(500)
                    ->helperText('Laissez vide sauf republication depuis une autre source.'),

                Select::make('products')
                    ->label('Produits liés')
                    ->relationship('products', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText('Le maillage interne : c’est ce qui transforme le trafic SEO en ventes.'),
            ]);
    }
}
