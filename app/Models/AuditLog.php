<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $actor_id
 * @property string $actor_type // App\Models\Admin | App\Models\User
 * @property string $event      // login | booking | wallet | deposit | refund | admin_action
 * @property array<string, mixed>|null $payload
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $created_at
 */
class AuditLog extends Model
{
    /** @var array<int, string> */
    protected $fillable = ['actor_id', 'actor_type', 'event', 'payload', 'ip_address', 'user_agent'];

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
    ];

    public $timestamps = true;

    /** Audit logs are immutable — there is no updated_at column. */
    public const UPDATED_AT = null;

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            return true;
        }

        return parent::save($options);
    }

    /** @return BelongsTo<Admin|User, static> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(
            match ($this->actor_type) {
                Admin::class => Admin::class,
                default      => User::class,
            },
            'actor_id'
        );
    }
}
