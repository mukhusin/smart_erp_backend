<?php

namespace Modules\Fleet\Actions;

use Illuminate\Support\Collection;
use Modules\Core\Notifications\Notice;
use Modules\Fleet\Models\Assignment;
use Modules\Fleet\Models\Compliance;
use Modules\Fleet\Models\Driver;
use Modules\Fleet\Models\FleetUnit;
use Modules\Fleet\Models\StatusChange;
use Modules\Fleet\Support\FleetAccess;

class FleetAlerts
{
    public const REMIND_DAYS = [30, 14, 7, 0];

    public function __construct(private readonly FleetAccess $access) {}

    /** @return array{ended: int, disposed: int, reminders: int} */
    public function run(): array
    {
        $ended = 0;
        foreach (Assignment::whereNull('ended_on')->whereNotNull('ends_on')->whereDate('ends_on', '<', today())->whereHas('unit')->get() as $a) {
            $a->update(['ended_on' => $a->ends_on->toDateString()]);
            $ended++;
        }

        $disposed = 0;
        foreach (FleetUnit::where('status', '!=', 'disposed')->whereHas('asset', fn ($q) => $q->where('status', 'disposed'))->get() as $unit) {
            StatusChange::create(['fleet_unit_id' => $unit->id, 'from' => $unit->status, 'to' => 'disposed', 'note' => "Asset {$unit->asset?->number} disposed"]);
            $unit->update(['status' => 'disposed', 'status_note' => 'Asset disposed']);
            $unit->assignments()->whereNull('ended_on')->update(['ended_on' => today()->toDateString()]);
            $disposed++;
        }

        $managers = $this->access->managers();
        $reminders = 0;
        foreach ($this->due() as $item) {
            $days = $item['days'];
            $when = $days > 0 ? "expires in {$days} day(s)" : ($days === 0 ? 'expires today' : 'expired '.abs($days).' day(s) ago');
            $notice = new Notice("{$item['what']} {$when}", "{$item['for']}: {$item['what']} {$when} (".$item['expires']->format('d M Y').'). Renew it.', $item['link'], 'shield-exclamation');
            foreach ($managers->merge($item['users'])->unique('id') as $user) {
                $user->notify($notice);
                $reminders++;
            }
        }

        return ['ended' => $ended, 'disposed' => $disposed, 'reminders' => $reminders];
    }

    /** Items whose reminder day is today: 30, 14, 7 days before, the day itself, then weekly when overdue. */
    private function due(): Collection
    {
        $hit = fn (int $days) => in_array($days, self::REMIND_DAYS, true) || ($days < 0 && abs($days) % 7 === 0);
        $out = collect();
        $items = Compliance::with(['unit.activeAssignment.driver.employee.user'])->where('is_current', true)->whereHas('unit', fn ($q) => $q->where('status', '!=', 'disposed'))
            ->whereDate('expires_on', '<=', today()->addDays(30))->get();
        foreach ($items as $c) {
            $days = (int) today()->diffInDays($c->expires_on, false);
            if ($hit($days)) {
                $out->push(['what' => Compliance::TYPES[$c->type] ?? $c->type, 'for' => $c->unit->label(), 'days' => $days, 'expires' => $c->expires_on, 'link' => "/fleet/units/{$c->fleet_unit_id}",
                    'users' => collect([$c->unit->activeAssignment?->driver?->employee?->user])->filter()]);
            }
        }
        foreach (Driver::with(['employee.user', 'licences'])->where('status', 'active')->get() as $d) {
            $l = $d->currentLicence();
            if (! $l) {
                continue;
            }
            $days = (int) today()->diffInDays($l->end_date, false);
            if ($days <= 30 && $hit($days)) {
                $out->push(['what' => 'Driving licence', 'for' => $d->name(), 'days' => $days, 'expires' => $l->end_date, 'link' => "/fleet/drivers/{$d->id}",
                    'users' => collect([$d->employee?->user])->filter()]);
            }
        }

        return $out;
    }
}
