<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Scans SOURCE CODE, not prose.
 *
 * A naive `str_contains($source, 'DB::table')` cannot tell a forbidden call from a
 * comment that documents its absence — and the P6-B0 files deliberately explain what
 * they do not do ("no Eloquent model, no DB::table, no raw SELECT"). Every such comment
 * would raise a false alarm, and the usual response — deleting the explanation — makes
 * the codebase worse to read.
 *
 * These helpers strip comments with the PHP tokenizer (and Blade/HTML comments for
 * views) so a security contract asserts on what actually EXECUTES.
 */
final class SourceScanner
{
    /** PHP source with every comment removed, string literals preserved. */
    public static function phpCode(string $path): string
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $code = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                // T_DOC_COMMENT and T_COMMENT are the only things dropped: string
                // literals stay, because SQL and class names live inside them.
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /** Blade source with {{-- … --}} and <!-- … --> comments removed. */
    public static function bladeMarkup(string $path): string
    {
        $source = (string) file_get_contents($path);
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;

        return preg_replace('/<!--.*?-->/s', '', $source) ?? $source;
    }

    /**
     * Forbidden tokens actually present in the executable part of a file.
     *
     * @param  list<string>  $forbidden
     * @return list<string>
     */
    public static function violations(string $code, array $forbidden): array
    {
        return array_values(array_filter(
            $forbidden,
            static fn (string $token): bool => str_contains($code, $token),
        ));
    }
}
