<?php

namespace App\Filament\Resources\ProductFiles\Pages;

use App\Filament\Resources\ProductFiles\ProductFileResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class CreateProductFile extends CreateRecord
{
    protected static string $resource = ProductFileResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $path = (string) ($data['uploaded_file'] ?? '');

        if ($path === '' || ! Storage::disk('private')->exists($path)) {
            throw new RuntimeException('Private product upload is unavailable.');
        }

        // From here on the upload physically exists on the private disk, so EVERY failure
        // has to remove it. A per-branch cleanup was tried first and left one hole: a
        // stream that could not be opened threw before any delete, stranding the file
        // with no row pointing at it. One catch around the whole body closes every exit
        // at once, including the ones a future edit might add.
        try {
            $stream = Storage::disk('private')->readStream($path);

            if (! is_resource($stream)) {
                throw new RuntimeException('Private product upload is unreadable.');
            }

            try {
                $checksum = hash_init('sha256');
                hash_update_stream($checksum, $stream);
            } finally {
                // Closed before any delete: the handle must not outlive the file.
                fclose($stream);
            }

            $originalName = trim((string) ($data['uploaded_original_name'] ?? ''));

            if ($originalName === '') {
                throw new RuntimeException('Private product upload name is unavailable.');
            }

            unset($data['uploaded_file'], $data['uploaded_original_name']);

            $data['storage_disk'] = 'private';
            $data['storage_path'] = $path;
            $data['original_name'] = $originalName;
            $data['size_bytes'] = Storage::disk('private')->size($path);
            $data['mime_type'] = Storage::disk('private')->mimeType($path) ?: null;
            $data['checksum_sha256'] = hash_final($checksum);
            $data['created_at'] = now();

            return parent::handleRecordCreation($data);
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($path);

            throw $exception;
        }
    }
}
