<?php

namespace Modules\Hr\Attendance;

use Illuminate\Support\Carbon;

/** Where device punches come from (a BioTime server today; others can be added). */
interface PunchSource
{
    public function reachable(): bool;

    /** @return iterable<array{emp_code: string, punch_time: string, punch_state?: string|null, verify_type?: string|null, area_alias?: string|null, terminal_sn?: string|null, terminal_alias?: string|null, external_id?: int|null}> */
    public function punches(Carbon $date): iterable;
}
