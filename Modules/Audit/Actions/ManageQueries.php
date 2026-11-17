<?php

namespace Modules\Audit\Actions;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Audit\Models\AuditQuery;
use Modules\Core\Notifications\Notice;
use Modules\Core\Support\Numbering;

/** Audit queries: the auditor asks about a record, the responsible person answers, the auditor closes it. */
class ManageQueries
{
    public function create(array $data, User $by): AuditQuery
    {
        $assignee = User::where('company_id', $by->company_id)->where('is_active', true)->find($data['assigned_to']);
        if (! $assignee) {
            throw ValidationException::withMessages(['assigned_to' => 'Choose an active user of the organisation.']);
        }
        $q = AuditQuery::create(['number' => Numbering::next(AuditQuery::class, 'AQ'), 'raised_by' => $by->id, 'status' => 'open']
            + collect($data)->only(['title', 'question', 'reference', 'link', 'area', 'assigned_to', 'due_date'])->all());
        $assignee->notify(new Notice('Audit query', "{$q->number}: {$q->title}".($q->due_date ? ' (answer by '.$q->due_date->format('d M Y').')' : ''), '/audit/queries', 'shield-check'));

        return $q;
    }

    public function respond(AuditQuery $q, string $response, User $by): AuditQuery
    {
        if ($q->status === 'closed') {
            throw ValidationException::withMessages(['query' => 'This query is closed.']);
        }
        if ($q->assigned_to !== $by->id && ! $by->can('audit.queries')) {
            abort(403, 'Only the person the query is addressed to can answer it.');
        }
        $q->update(['response' => $response, 'responded_by' => $by->id, 'responded_at' => now(), 'status' => 'answered']);
        $q->raiser?->notify(new Notice('Audit query answered', "{$q->number}: {$q->title}", '/audit/queries', 'shield-check'));

        return $q->refresh();
    }

    public function close(AuditQuery $q, ?string $note, User $by): AuditQuery
    {
        if ($q->status === 'closed') {
            throw ValidationException::withMessages(['query' => 'This query is already closed.']);
        }
        $q->update(['status' => 'closed', 'closing_note' => $note, 'closed_at' => now()]);

        return $q->refresh();
    }

    public function reopen(AuditQuery $q, string $note, User $by): AuditQuery
    {
        if ($q->status !== 'answered') {
            throw ValidationException::withMessages(['query' => 'Only an answered query can be sent back.']);
        }
        $q->update(['status' => 'open', 'closing_note' => $note]);
        $q->assignee?->notify(new Notice('Audit query sent back', "{$q->number}: {$note}", '/audit/queries', 'shield-check'));

        return $q->refresh();
    }
}
