<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Product;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Produit')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('Titre')
                                ->required()
                                ->maxLength(180)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Set $set, ?string $state): mixed => $set('slug', Str::slug($state ?? ''))),
                            TextInput::make('slug')
                                ->required()
                                ->maxLength(180)
                                ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/')
                                ->unique(Product::class, 'slug', ignoreRecord: true),
                            Select::make('type')
                                ->options(collect(ProductType::cases())->mapWithKeys(fn (ProductType $type): array => [$type->value => ucfirst($type->value)]))
                                ->required(),
                            Select::make('status')
                                ->options(collect(ProductStatus::cases())->mapWithKeys(fn (ProductStatus $status): array => [$status->value => ucfirst($status->value)]))
                                ->default(ProductStatus::Draft->value)
                                ->live()
                                ->required(),
                            Select::make('categories')
                                ->relationship('categories', 'name')
                                ->multiple()
                                ->preload()
                                ->searchable()
                                ->columnSpanFull(),
                            Textarea::make('short_description')
                                ->label('Description courte')
                                ->rows(3)
                                ->maxLength(500)
                                ->columnSpanFull(),
                            Textarea::make('long_description')
                                ->label('Description longue')
                                ->rows(8)
                                ->columnSpanFull(),
                            FileUpload::make('cover_image_path')
                                ->label('Image de couverture')
                                ->disk('public')
                                ->directory('catalog-covers')
                                ->visibility('public')
                                ->image()
                                ->maxSize(5120)
                                ->columnSpanFull(),
                        ]),
                    ]),
                Section::make('Prix XOF')
                    ->schema([
                        Repeater::make('prices')
                            ->relationship(modifyQueryUsing: fn (Builder $query): Builder => $query->where('currency', 'XOF'))
                            ->minItems(1)
                            ->maxItems(1)
                            ->defaultItems(1)
                            ->schema([
                                Hidden::make('currency')->default('XOF'),
                                TextInput::make('price_minor')
                                    ->label('Prix XOF')
                                    ->integer()
                                    ->minValue(0)
                                    ->required(),
                                TextInput::make('compare_at_price_minor')
                                    ->label('Prix barre XOF')
                                    ->integer()
                                    ->minValue(0),
                                Toggle::make('is_active')
                                    ->label('Prix actif')
                                    ->default(true)
                                    ->required(),
                            ])
                            ->columns(3),
                    ]),
                Section::make('Publication et SEO')
                    ->schema([
                        DateTimePicker::make('published_at')
                            ->label('Publication')
                            ->required(fn (Get $get): bool => $get('status') === ProductStatus::Published->value)
                            ->seconds(false),
                        TextInput::make('meta_title')->maxLength(180),
                        Textarea::make('meta_description')->rows(3)->maxLength(320),
                    ])
                    ->columns(2),
            ]);
    }
}
