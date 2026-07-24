<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Support\ByteRange;
use App\Support\InvalidByteRange;

final class ByteRangeParser
{
    public function parse(?string $header, int $size): ?ByteRange
    {
        if ($header === null || $header === '') {
            return null;
        }

        if ($size <= 0 || str_contains($header, ',')
            || preg_match('/\Abytes=(\d*)-(\d*)\z/', $header, $matches) !== 1
            || ($matches[1] === '' && $matches[2] === '')) {
            throw new InvalidByteRange(max(0, $size));
        }

        if ($matches[1] === '') {
            $suffix = $this->integer($matches[2], $size);
            if ($suffix <= 0) {
                throw new InvalidByteRange($size);
            }

            $length = min($suffix, $size);

            return new ByteRange($size - $length, $size - 1, $size);
        }

        $start = $this->integer($matches[1], $size);
        if ($start >= $size) {
            throw new InvalidByteRange($size);
        }

        $end = $matches[2] === '' ? $size - 1 : $this->integer($matches[2], $size);
        if ($end < $start) {
            throw new InvalidByteRange($size);
        }

        return new ByteRange($start, min($end, $size - 1), $size);
    }

    private function integer(string $digits, int $size): int
    {
        $normalised = ltrim($digits, '0');
        $normalised = $normalised === '' ? '0' : $normalised;
        $max = (string) PHP_INT_MAX;

        if (strlen($normalised) > strlen($max)
            || (strlen($normalised) === strlen($max) && strcmp($normalised, $max) > 0)) {
            throw new InvalidByteRange($size);
        }

        return (int) $normalised;
    }
}
