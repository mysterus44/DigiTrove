<?php

namespace App\Services\Analytics\Read\Data;

use App\Support\AnalyticsDashboardConfig;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class AnalyticsDateRange
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public static function make(?string $from = null, ?string $to = null): self
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $toDate = $to === null ? $today : self::parse($to);
        $fromDate = $from === null
            ? $toDate->subDays(AnalyticsDashboardConfig::defaultDays() - 1)
            : self::parse($from);

        $inclusiveDays = (int) $fromDate->diffInDays($toDate) + 1;

        if ($fromDate->greaterThan($toDate)
            || $toDate->greaterThan($today)
            || $inclusiveDays > AnalyticsDashboardConfig::maxDays()) {
            throw new InvalidArgumentException('Invalid analytics date range.');
        }

        return new self($fromDate, $toDate);
    }

    public function fromString(): string
    {
        return $this->from->toDateString();
    }

    public function toString(): string
    {
        return $this->to->toDateString();
    }

    /** @return list<string> */
    public function days(): array
    {
        $days = [];

        for ($day = $this->from; $day->lessThanOrEqualTo($this->to); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    private static function parse(string $value): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException('Invalid analytics date.');
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid analytics date.');
        }

        if ($date === false || $date->toDateString() !== $value) {
            throw new InvalidArgumentException('Invalid analytics date.');
        }

        return $date;
    }
}
