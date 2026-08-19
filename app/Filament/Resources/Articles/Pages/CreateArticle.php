<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use App\Support\ArticleContent;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;

    /**
     * `public_id` and `reading_minutes` are derived, never typed.
     *
     * Reading time is computed from the body HERE rather than at read time: it depends on
     * the content alone, so a write-time value costs nothing and — unlike a view counter —
     * carries no request, no race and nothing a visitor can forge.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['public_id'] = (string) Str::uuid();
        $data['reading_minutes'] = ArticleContent::readingMinutes((string) ($data['body'] ?? ''));

        return $data;
    }
}
