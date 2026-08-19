<?php

namespace App\Filament\Resources\Redirects\Schemas;

use App\Models\Redirect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RedirectForm
{
    /**
     * Mirrors `redirects_from_path_format_check`: an internal absolute path.
     *
     * The two negative lookaheads are the whole point — `//host` and `/\host` both start
     * with a slash and browsers read them as absolute URLs on another host. That is the
     * open redirect P6-D2 closed on `/r/{code}`, and a redirect table is an easier target.
     */
    private const PATH_PATTERN = '/\A\/(?!\/)(?!\\\\)\S*\z/';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('from_path')
                ->label('Ancienne URL')
                ->required()
                ->maxLength(500)
                ->regex(self::PATH_PATTERN)
                ->unique(Redirect::class, 'from_path', ignoreRecord: true)
                ->helperText('Chemin interne commençant par « / ». Jamais un domaine externe.'),

            TextInput::make('to_path')
                ->label('Nouvelle URL')
                ->required()
                ->maxLength(500)
                ->regex(self::PATH_PATTERN)
                ->different('from_path')
                ->helperText('La base refuse une chaîne 301 → 301 : la destination ne doit pas être elle-même redirigée.'),

            Select::make('status_code')
                ->label('Code')
                ->options([
                    301 => '301 — permanent',
                    308 => '308 — permanent, méthode conservée',
                ])
                ->default(301)
                ->required(),
        ]);
    }
}
