<?php

namespace App\Services;

use Carbon\CarbonInterface;

final class PlannedDay
{
    public const WORK = 'work';

    public const OFF = 'off';

    public const HOLIDAY = 'holiday';

    public const LEAVE = 'leave';

    public function __construct(
        public readonly CarbonInterface $date,
        public readonly string $kind,
        public readonly string $source,
        public readonly ?string $code,
        public readonly string $label,
        public readonly ?string $start = null,
        public readonly ?string $end = null,
        public readonly bool $crossesMidnight = false,
        public readonly float $hours = 0,
    ) {}

    public static function working(CarbonInterface $date, string $source, ?string $code, string $label, string $start, string $end, int $breakMinutes = 0): self
    {
        $cross = $end <= $start;
        $minutes = self::minutes($end) - self::minutes($start) + ($cross ? 1440 : 0) - $breakMinutes;

        return new self($date, self::WORK, $source, $code, $label, $start, $end, $cross, round(max(0, $minutes) / 60, 2));
    }

    public function isWorking(): bool
    {
        return $this->kind === self::WORK;
    }

    public function startsAt(): ?CarbonInterface
    {
        return $this->start ? $this->date->copy()->setTimeFromTimeString($this->start) : null;
    }

    public function endsAt(): ?CarbonInterface
    {
        return $this->end ? $this->date->copy()->addDays($this->crossesMidnight ? 1 : 0)->setTimeFromTimeString($this->end) : null;
    }

    public function toArray(): array
    {
        return [
            'date' => $this->date->toDateString(), 'kind' => $this->kind, 'source' => $this->source, 'code' => $this->code, 'label' => $this->label,
            'start' => $this->start, 'end' => $this->end, 'crosses_midnight' => $this->crossesMidnight, 'hours' => $this->hours,
        ];
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [1 => 0]);

        return $h * 60 + $m;
    }
}
