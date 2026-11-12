<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SingleDeviceSessionManager
{
    private const CACHE_PREFIX = 'user_session_';

    public function registerLogin(int $userId, string $currentSessionId): void
    {
        $previousSessionId = Cache::get($this->cacheKey($userId));

        if ($previousSessionId && $previousSessionId !== $currentSessionId) {
            $this->destroySession($previousSessionId);
            Log::info("Single Device: Destroyed previous session {$previousSessionId} for user {$userId}");
        }

        Cache::put($this->cacheKey($userId), $currentSessionId, $this->ttl());

        DB::table('users')->where('id', $userId)->update(['remember_token' => null]);
    }

    public function validateSession(int $userId, string $currentSessionId): bool
    {
        $registeredSessionId = Cache::get($this->cacheKey($userId));

        if (! $registeredSessionId) {
            Cache::put($this->cacheKey($userId), $currentSessionId, $this->ttl());

            return true;
        }

        if ($registeredSessionId !== $currentSessionId) {
            Log::warning("Single Device: Session mismatch for user {$userId}");

            return false;
        }

        Cache::put($this->cacheKey($userId), $currentSessionId, $this->ttl());

        return true;
    }

    public function clearSessionRegistration(int $userId): void
    {
        Cache::forget($this->cacheKey($userId));
    }

    protected function destroySession(string $sessionId): void
    {
        $driver = Config::get('session.driver');

        if ($driver === 'database') {
            DB::table(Config::get('session.table', 'sessions'))->where('id', $sessionId)->delete();

            return;
        }

        if ($driver === 'file') {
            $path = storage_path('framework/sessions/'.$sessionId);
            if (File::exists($path)) {
                File::delete($path);
            }

            return;
        }

        try {
            $handler = app('session')->getHandler();
            if (method_exists($handler, 'destroy')) {
                $handler->destroy($sessionId);
            }
        } catch (\Throwable $e) {
            Log::warning('Single Device: Failed to destroy session via handler', ['exception' => $e->getMessage()]);
        }
    }

    protected function cacheKey(int $userId): string
    {
        return self::CACHE_PREFIX.$userId;
    }

    protected function ttl(): CarbonInterface
    {
        $minutes = (int) Config::get('session.lifetime', 120);

        return now()->addMinutes($minutes > 0 ? $minutes : 120);
    }
}
