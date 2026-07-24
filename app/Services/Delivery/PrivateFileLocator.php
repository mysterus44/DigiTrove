<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Models\ProductFile;
use App\Support\DeliveryConfig;
use App\Support\DownloadAccessDenied;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class PrivateFileLocator
{
    public function diskFor(ProductFile $file): Filesystem
    {
        if (! in_array($file->storage_disk, DeliveryConfig::privateDisks(), true)
            || ! $this->validPath((string) $file->storage_path)) {
            throw new DownloadAccessDenied;
        }

        try {
            return Storage::disk($file->storage_disk);
        } catch (Throwable) {
            throw new DownloadAccessDenied;
        }
    }

    public function assertResolvable(ProductFile $file): void
    {
        try {
            if (! $this->diskFor($file)->exists($file->storage_path)) {
                throw new DownloadAccessDenied;
            }
        } catch (DownloadAccessDenied $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DownloadAccessDenied;
        }
    }

    public function size(ProductFile $file): int
    {
        try {
            $size = $this->diskFor($file)->size($file->storage_path);
        } catch (Throwable) {
            throw new DownloadAccessDenied;
        }

        if ($size !== (int) $file->size_bytes) {
            throw new DownloadAccessDenied;
        }

        return $size;
    }

    /**
     * @return resource
     */
    public function readStream(ProductFile $file)
    {
        try {
            $stream = $this->diskFor($file)->readStream($file->storage_path);
        } catch (Throwable) {
            throw new DownloadAccessDenied;
        }

        if (! is_resource($stream)) {
            throw new DownloadAccessDenied;
        }

        return $stream;
    }

    public function xAccelPath(ProductFile $file): string
    {
        $disk = config("filesystems.disks.{$file->storage_disk}");
        if (! is_array($disk) || ($disk['driver'] ?? null) !== 'local') {
            throw new RuntimeException('X-Accel requires a local private disk.');
        }

        $prefix = DeliveryConfig::xAccelPrefix();
        $segments = array_map('rawurlencode', explode('/', (string) $file->storage_path));

        return $prefix.'/'.implode('/', $segments);
    }

    private function validPath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
