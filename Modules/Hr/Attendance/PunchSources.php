<?php

namespace Modules\Hr\Attendance;

use App\Models\AttendanceSetting;
use App\Services\CompanyContext;

/** The punch source configured for the current company, or null (manual entry and CSV only). */
class PunchSources
{
    public function forCurrentCompany(): ?PunchSource
    {
        $s = AttendanceSetting::current();

        return $s->source === 'biotime' && ! empty($s->connection['host']) ? new BiotimeSource($s->connection, app(CompanyContext::class)->id()) : null;
    }

    /** Where employees are registered on the fingerprint devices (BioTime), or null when the company does not use them. */
    public function personnelForCurrentCompany(): ?BiotimePersonnel
    {
        $source = $this->forCurrentCompany();

        return $source instanceof BiotimeSource ? new BiotimePersonnel($source->connectionName()) : null;
    }
}
