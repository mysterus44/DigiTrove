<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Delivery\DownloadFileService;
use App\Support\DownloadAccessDenied;
use App\Support\InvalidByteRange;
use App\Support\PreparedDownload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadFileController
{
    public function __invoke(
        Request $request,
        string $grantPublicId,
        DownloadFileService $files,
    ): Response|StreamedResponse {
        if (collect(['token', 'attempt', 'grant'])->contains(
            static fn (string $key): bool => $request->query->has($key),
        )) {
            return $this->denied();
        }

        $attempt = $request->cookie('dl_attempt');
        if (! is_string($attempt)) {
            hash('sha256', '');

            return $this->denied();
        }

        try {
            $download = $files->prepare(
                $grantPublicId,
                $attempt,
                $request->header('Range'),
                $request->isMethod('HEAD'),
            );
        } catch (InvalidByteRange $exception) {
            return response('', 416, [
                ...$this->securityHeaders(),
                'Accept-Ranges' => 'bytes',
                'Content-Range' => "bytes */{$exception->size}",
            ]);
        } catch (DownloadAccessDenied) {
            return $this->denied();
        }

        $headers = $this->fileHeaders($download);
        $status = $download->partial ? 206 : 200;
        if ($download->head || $download->xAccelPath !== null) {
            if ($download->xAccelPath !== null) {
                $headers['X-Accel-Redirect'] = $download->xAccelPath;
            }

            return response('', $status, $headers);
        }

        return response()->stream(
            function () use ($download): void {
                $stream = $download->stream;

                try {
                    $this->positionStream($stream, $download->start, $download->chunkBytes);
                    $remaining = $download->length();

                    while ($remaining > 0 && ! feof($stream)) {
                        $chunk = fread($stream, min($download->chunkBytes, $remaining));
                        if ($chunk === false) {
                            break;
                        }

                        echo $chunk;
                        $remaining -= strlen($chunk);
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            },
            $status,
            $headers,
        );
    }

    /**
     * @return array<string, string>
     */
    private function fileHeaders(PreparedDownload $download): array
    {
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '_', basename(str_replace('\\', '/', $download->fileName)));
        $name = is_string($name) && trim($name) !== '' ? trim($name) : 'download.bin';
        $fallback = Str::ascii($name);
        $fallback = preg_replace('/[^A-Za-z0-9._ -]/', '_', $fallback) ?: 'download.bin';

        $headers = [
            ...$this->securityHeaders(),
            'Accept-Ranges' => 'bytes',
            'Content-Type' => $download->mimeType,
            'Content-Length' => (string) $download->length(),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $fallback),
            'ETag' => '"'.$download->checksum.'"',
        ];

        if ($download->partial) {
            $headers['Content-Range'] = "bytes {$download->start}-{$download->end}/{$download->size}";
        }

        return $headers;
    }

    /**
     * @param  resource  $stream
     */
    private function positionStream($stream, int $offset, int $chunkBytes): void
    {
        if ($offset === 0) {
            return;
        }

        $meta = stream_get_meta_data($stream);
        if (($meta['seekable'] ?? false) && fseek($stream, $offset) === 0) {
            return;
        }

        $remaining = $offset;
        while ($remaining > 0 && ! feof($stream)) {
            $discarded = fread($stream, min($chunkBytes, $remaining));
            if ($discarded === false || $discarded === '') {
                throw new DownloadAccessDenied;
            }
            $remaining -= strlen($discarded);
        }

        if ($remaining !== 0) {
            throw new DownloadAccessDenied;
        }
    }

    private function denied(): Response
    {
        return response('Download unavailable.', 404, $this->securityHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function securityHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
