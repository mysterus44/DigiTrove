<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

final class DownloadLandingController
{
    public function __invoke(string $grantPublicId): Response
    {
        $nonce = base64_encode(random_bytes(18));
        $content = view('downloads.exchange', [
            'grantPublicId' => $grantPublicId,
            'nonce' => $nonce,
        ])->render();

        return response($content)->withHeaders([
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}'; connect-src 'self'; style-src 'nonce-{$nonce}'; img-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
