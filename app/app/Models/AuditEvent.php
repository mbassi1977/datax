<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    use HasUuids;

    protected $table = 'audit.audit_events';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    public static function record(string $action, ?Model $entity = null, ?array $old = null, ?array $new = null, ?string $tenantId = null, ?string $userId = null): void
    {
        $request = app()->bound('request') ? request() : null;
        $user = auth()->user();
        $strip = fn (?array $v) => $v === null ? null : array_diff_key($v, array_flip(['password', 'remember_token']));

        static::create([
            'tenant_id' => $tenantId ?? $entity?->tenant_id ?? $user?->tenant_id,
            'user_id' => $userId ?? $user?->getKey(),
            'action' => $action,
            'entity_type' => $entity ? $entity::class : null,
            'entity_id' => $entity?->getKey(),
            'old_values' => $strip($old),
            'new_values' => $strip($new),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
