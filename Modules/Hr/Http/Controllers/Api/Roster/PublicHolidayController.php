<?php

namespace Modules\Hr\Http\Controllers\Api\Roster;

use App\Http\Controllers\Controller;
use App\Models\PublicHoliday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Hr\Http\Requests\Roster\PublicHolidayRequest;

/**
 * Public holidays: yearly on the same day (e.g. Union Day, 26 April) or once on a date (e.g. Eid, which moves each year).
 * Holidays are days off on working hours; leave days do not count them.
 *
 * @tags Roster: Public holidays
 */
class PublicHolidayController extends Controller
{
    /**
     * List holidays with the date each falls on in the given year (default this year), in date order.
     *
     * @response array{data: list<array{id: int, title: string, type: string, day: int|null, month: int|null, date: string|null, is_active: bool}>, meta: array{year: int}}
     */
    public function index(Request $request): JsonResponse
    {
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']])['year'] ?? now()->year);
        $rows = PublicHoliday::all()
            ->filter(fn (PublicHoliday $h) => $h->recurring() || $h->holiday_date->year === $year)
            ->map(fn (PublicHoliday $h) => self::row($h, $year))
            ->sortBy(fn ($r) => $r['date'] ?? sprintf('%04d-%02d-%02d', $year, $r['month'], $r['day']))->values();

        return response()->json(['data' => $rows, 'meta' => ['year' => $year]]);
    }

    /**
     * Add a holiday.
     *
     * @response array{data: array{id: int, title: string, type: string, day: int|null, month: int|null, date: string|null, is_active: bool}}
     */
    public function store(PublicHolidayRequest $request): JsonResponse
    {
        $holiday = PublicHoliday::create($this->data($request));

        return response()->json(['data' => self::row($holiday, $holiday->holiday_date?->year ?? now()->year)], 201);
    }

    /**
     * Update a holiday.
     *
     * @response array{data: array{id: int, title: string, type: string, day: int|null, month: int|null, date: string|null, is_active: bool}}
     */
    public function update(PublicHolidayRequest $request, PublicHoliday $publicHoliday): JsonResponse
    {
        $publicHoliday->update($this->data($request, $publicHoliday));

        return response()->json(['data' => self::row($publicHoliday, $publicHoliday->holiday_date?->year ?? now()->year)]);
    }

    /** Delete a holiday. */
    public function destroy(PublicHoliday $publicHoliday): Response
    {
        $publicHoliday->delete();

        return response()->noContent();
    }

    public static function row(PublicHoliday $h, int $year): array
    {
        return [
            'id' => $h->id, 'title' => $h->title, 'type' => $h->recurring() ? 'yearly' : 'once',
            'day' => $h->recurring() ? (int) $h->day_number : null, 'month' => $h->recurring() ? (int) $h->month_number : null,
            'date' => $h->dateIn($year)?->toDateString(), 'is_active' => (bool) ($h->is_active ?? true),
        ];
    }

    private function data(PublicHolidayRequest $request, ?PublicHoliday $holiday = null): array
    {
        $data = $request->validated();

        $yearly = $data['type'] === 'yearly';
        if ($yearly && ! checkdate((int) $data['month'], (int) $data['day'], 2024)) {
            throw ValidationException::withMessages(['day' => 'This day does not exist in that month.']);
        }
        $date = $yearly ? null : Carbon::parse($data['date'])->startOfDay();
        $clash = PublicHoliday::query()->when($holiday, fn ($q) => $q->whereKeyNot($holiday->id))->get()->first(fn (PublicHoliday $h) => $yearly
            ? $h->recurring() && (int) $h->day_number === (int) $data['day'] && (int) $h->month_number === (int) $data['month']
            : $h->fallsOn($date));
        if ($clash) {
            throw ValidationException::withMessages([$yearly ? 'day' : 'date' => "{$clash->title} is already on this day."]);
        }

        return [
            'title' => trim($data['title']), 'holiday_date' => $date,
            'day_number' => $yearly ? sprintf('%02d', $data['day']) : null, 'month_number' => $yearly ? sprintf('%02d', $data['month']) : null,
            'is_active' => $data['is_active'] ?? $holiday?->is_active ?? true,
        ];
    }
}
