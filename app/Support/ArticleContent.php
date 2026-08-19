<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * P7. Turns article Markdown into safe HTML, and measures reading time.
 *
 * ⚠️ `html_input: strip` IS THE SECURITY BOUNDARY. CommonMark passes raw HTML through by
 * default, so a `<script>` pasted into the editor would reach the page verbatim. Stripping
 * is stronger than an allowlist: there is no tag list to get wrong, and nothing to keep in
 * sync as the sanitizer evolves. `allow_unsafe_links: false` closes the other half —
 * `javascript:`, `data:` and `vbscript:` hrefs never render.
 *
 * Rendering happens SERVER-SIDE at read time, from the stored Markdown. Storing rendered
 * HTML would freeze today's sanitizer into the database: fixing a future escape would then
 * require rewriting every row instead of shipping one deploy.
 */
final class ArticleContent
{
    /** Average adult reading speed, words per minute. Deterministic, never user input. */
    private const WORDS_PER_MINUTE = 200;

    public static function toHtml(string $markdown): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Reading time, derived from the body at WRITE time.
     *
     * This is the one metric a page may carry without a counter: it depends on the content
     * alone, so it needs no request, no write on read, and cannot be forged by a visitor.
     */
    public static function readingMinutes(string $markdown): int
    {
        $words = preg_split('/\s+/u', trim(strip_tags(self::toHtml($markdown))), -1, PREG_SPLIT_NO_EMPTY);

        return max(1, (int) ceil(count($words ?: []) / self::WORDS_PER_MINUTE));
    }
}
