<?php

namespace App\Models\Concerns;

use App\Models\AuditEvent;

/** Registra creación, cambio y eliminación en audit.audit_events (valor anterior / nuevo). */
trait Auditable
{
    private static array $auditHidden = ['password', 'remember_token'];

    public static function bootAuditable(): void
    {
        static::created(fn ($m) => AuditEvent::record('created', $m, null, $m->getAttributes()));

        static::updated(function ($m) {
            $new = $m->getChanges();
            unset($new['updated_at']);
            if ($new === []) {
                return;
            }
            $old = array_intersect_key($m->getOriginal(), $new);
            AuditEvent::record('updated', $m, $old, $new);
        });

        static::deleted(fn ($m) => AuditEvent::record('deleted', $m, $m->getAttributes(), null));
    }

    public static function auditSanitize(?array $values): ?array
    {
        return $values === null ? null : array_diff_key($values, array_flip(self::$auditHidden));
    }
}
