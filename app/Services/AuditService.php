<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Tracks important domain actions for auditability.
 *
 * Events: login | booking | wallet | deposit | refund | admin_action
 */
class AuditService
{
    /**
     * Write an audit log entry.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function log(
        ?int $actorId,
        string $event,
        ?array $payload = null,
        ?string $actorType = null
    ): AuditLog {
        $actorType ??= $this->resolveActorType();

        return AuditLog::create([
            'actor_id'   => $actorId,
            'actor_type' => $actorType,
            'event'      => $event,
            'payload'    => $payload,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Log an admin-triggered action.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function adminAction(Admin $admin, string $event, ?array $payload = null): AuditLog
    {
        return $this->log($admin->id, $event, $payload, Admin::class);
    }

    /**
     * Log a user-triggered action.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function userAction(User $user, string $event, ?array $payload = null): AuditLog
    {
        return $this->log($user->id, $event, $payload, User::class);
    }

    /**
     * Resolve actor type from the current auth guard (fallback: user).
     */
    protected function resolveActorType(): string
    {
        if (auth('admin')->check()) {
            return Admin::class;
        }

        return User::class;
    }

    /**
     * Query audit logs with optional filters.
     *
     * @return \Illuminate\Database\Eloquent\Builder<AuditLog>
     */
    public function query(?string $event = null, ?int $actorId = null, ?string $actorType = null)
    {
        return AuditLog::query()
            ->when($event, fn ($q) => $q->where('event', $event))
            ->when($actorId, fn ($q) => $q->where('actor_id', $actorId))
            ->when($actorType, fn ($q) => $q->where('actor_type', $actorType))
            ->latest();
    }
}
