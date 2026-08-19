<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P7. Mirrors `articles_status_check`; the database remains the authority.
 *
 * `archived` is not a soft delete: the row keeps its slug and stays restorable, but it
 * leaves the public site AND the sitemap. Deleting would free the slug and let a later
 * article silently inherit the SEO of a different page.
 */
enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Published => 'Publié',
            self::Archived => 'Archivé',
        };
    }
}
