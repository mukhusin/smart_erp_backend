<?php

namespace Database\Seeders\Demo;

use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\StepApprovers;

class Demo
{
    public string $password;

    /** @var list<string> */
    public array $notes = [];

    public function __construct(public Company $company)
    {
        $this->password = Hash::make('password');
    }

    public function note(string $message): void
    {
        $this->notes[] = $message;
    }

    public function user(string $username): User
    {
        return User::where('company_id', $this->company->id)->where('username', $username)->firstOrFail();
    }

    /** Calls the app's own API as the user; returns the decoded body, or null (noted) when it fails. */
    public function api(string $method, string $uri, array $data = [], string|User $as = 'hr', bool $quiet = false): ?array
    {
        app('auth')->forgetGuards();
        $user = $as instanceof User ? $as : $this->user($as);
        app('auth')->guard('web')->setUser($user);
        $as = $user->username;
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        $request = $method === 'GET'
            ? Request::create('/api/v1/'.ltrim($uri, '/'), 'GET', $data, [], [], $server)
            : Request::create('/api/v1/'.ltrim($uri, '/'), $method, [], [], [], $server, json_encode($data));
        $response = app(Kernel::class)->handle($request);
        $body = json_decode((string) $response->getContent(), true);
        if ($response->getStatusCode() >= 300) {
            if (! $quiet) {
                $errors = is_array($body['errors'] ?? null) ? json_encode(array_slice($body['errors'], 0, 3)) : '';
                $this->note("$method $uri as $as → {$response->getStatusCode()} ".($body['message'] ?? '')." $errors");
            }

            return null;
        }

        return is_array($body) ? $body : [];
    }

    /** Approves pending requests of the processes step by step as the real approvers; $keep(instance) leaves some waiting. */
    public function settle(array|string $processes, ?callable $keep = null, int $keepLatest = 0): int
    {
        $done = 0;
        foreach ((array) $processes as $process) {
            $all = WorkflowInstance::where('process', $process)->where('status', 'pending')->orderBy('id')->get();
            $left = $keepLatest ? $all->slice(0, max(0, $all->count() - $keepLatest)) : $all;
            foreach ($left as $instance) {
                if ($keep && $keep($instance)) {
                    continue;
                }
                for ($guard = 0; $guard < 8 && $instance->refresh()->status === 'pending'; $guard++) {
                    $user = $this->approverFor($instance);
                    if (! $user || ! $this->api('POST', "workflow/requests/{$instance->id}/approve", ['comment' => 'Approved'], $user)) {
                        break;
                    }
                }
                $done += $instance->refresh()->status === 'approved' ? 1 : 0;
            }
        }

        return $done;
    }

    public function approverFor(WorkflowInstance $instance): ?User
    {
        $engine = app(\Modules\Workflow\Services\WorkflowEngine::class);
        $step = $instance->currentStep();
        $pool = ($step ? app(StepApprovers::class)->for($instance, $step) : collect())
            ->merge(User::where('company_id', $this->company->id)->whereIn('username', ['hr', 'dg', 'dhr', 'dfinance', 'dplanning', 'hrmanager', 'hrofficer', 'finmanager', 'accountant'])->get());
        foreach ($pool as $u) {
            if ($u->id === $instance->initiated_by || $u->hasRole(['internal-auditor', 'external-auditor'])) {
                continue;
            }
            if ($engine->canAct($u, $instance)) {
                return $u;
            }
        }

        return null;
    }

    public function id(?array $body): ?int
    {
        return $body['data']['id'] ?? null;
    }

    public function as(string $username): User
    {
        $u = $this->user($username);
        auth()->setUser($u);

        return $u;
    }
}
