<?php

namespace Modules\Workflow\Jobs;

use App\Services\CompanyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Workflow\Models\WorkflowAction;
use Modules\Workflow\Models\WorkflowActionLog;

/** Posts a workflow event to another system; signed with the action's secret, retried on failure. */
class SendWorkflowWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $companyId, public readonly int $actionId, public readonly ?int $instanceId, public readonly string $event, public readonly array $payload) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        app(CompanyContext::class)->run($this->companyId, function () {
            $action = WorkflowAction::find($this->actionId);
            if (! $action || ! $action->url) {
                return;
            }
            $body = json_encode($this->payload);
            $request = Http::timeout(20)->acceptJson()->withBody($body, 'application/json');
            if ($action->secret) {
                $request = $request->withHeaders(['X-Signature' => hash_hmac('sha256', $body, $action->secret)]);
            }
            $log = fn (string $status, string $detail) => WorkflowActionLog::create(['workflow_action_id' => $action->id, 'workflow_instance_id' => $this->instanceId, 'event' => $this->event,
                'status' => $status, 'detail' => Str::limit($detail, 490)]);
            try {
                $response = $request->post($action->url);
            } catch (\Throwable $e) {
                $log('failed', 'Could not reach: '.$e->getMessage());
                throw $e;
            }
            if (! $response->successful()) {
                $log('failed', "HTTP {$response->status()}");
                throw new \RuntimeException("Webhook answered HTTP {$response->status()}");
            }
            $log('sent', "HTTP {$response->status()}");
        });
    }
}
