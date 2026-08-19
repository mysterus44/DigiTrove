<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use App\Support\ArticleContent;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
            // ⚠️ NO ForceDeleteAction. `BlogPolicy::forceDelete()` refuses it for everyone,
            // so the button would be a guaranteed 403 — an affordance that lies. Destroying
            // an article frees its slug, and a later article inheriting that URL would
            // inherit the SEO and the backlinks of a different page.
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['reading_minutes'] = ArticleContent::readingMinutes((string) ($data['body'] ?? ''));

        return $data;
    }
}
