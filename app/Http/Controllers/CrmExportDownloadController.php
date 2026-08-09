<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Crm\CrmExportService;
use App\Support\CrmConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * P6-B1 export download.
 *
 * EVERY refusal returns the SAME flat 404. A 403 for "exists but not yours" and a 404
 * for "does not exist" would together form an existence oracle over other admins'
 * exports, so the distinction is never exposed.
 *
 * There is no public URL, no bearer token and no anonymous signed link: access is
 * decided server-side on every single request from the session identity.
 */
final class CrmExportDownloadController
{
    public function __invoke(Request $request, int $export, CrmExportService $exports): Response|StreamedResponse
    {
        try {
            if (! CrmConfig::exportsEnabled() || ! Gate::allows('manageCustomerRelationships')) {
                return $this->denied();
            }

            $row = $exports->get($export);
        } catch (Throwable) {
            return $this->denied();
        }

        if ($row === null
            || $row['status'] !== 'completed'
            // The artefact is an audited, NOMINATIVE record: ownership is part of the
            // audit trail, not a convenience. Another admin is refused.
            || $row['requested_by_user_id'] !== $request->user()?->id
            || $row['storage_disk'] === null
            || $row['storage_path'] === null) {
            return $this->denied();
        }

        if ($this->expired($row['expires_at'])) {
            return $this->denied();
        }

        try {
            $disk = Storage::disk((string) $row['storage_disk']);

            if (! $disk->exists((string) $row['storage_path'])) {
                return $this->denied();
            }

            $stream = $disk->readStream((string) $row['storage_path']);
        } catch (Throwable) {
            return $this->denied();
        }

        if (! is_resource($stream)) {
            return $this->denied();
        }

        // The download name is derived from the public id, never from the storage path
        // and never from a segment name — neither may leak to the client.
        $filename = 'crm-export-'.$row['public_id'].'.csv';

        return response()->stream(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            ...$this->securityHeaders(),
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Length' => (string) $row['size_bytes'],
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function expired(mixed $expiresAt): bool
    {
        try {
            return $expiresAt !== null && now()->greaterThanOrEqualTo($expiresAt);
        } catch (Throwable) {
            // An unparseable expiry is treated as expired: fail closed.
            return true;
        }
    }

    private function denied(): Response
    {
        return response('Export unavailable.', 404, $this->securityHeaders());
    }

    /** @return array<string, string> */
    private function securityHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ];
    }
}
