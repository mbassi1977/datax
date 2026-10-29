<?php

namespace App\Imports;

use App\Models\User;
use App\Support\Access\AccessService;
use Illuminate\Support\Facades\DB;

/** Datos compartidos durante una importación: usuario, alcance y organizaciones del tenant. */
class ImportContext
{
    public ?int $currentRow = null;
    /** @var array<int, array> avisos de revisión (p. ej. separación de nombres dudosa) */
    public array $warnings = [];
    private array $allowed = [];
    private ?array $orgs = null;

    public function __construct(public User $user, public AccessService $access, public array $options = [], public ?string $profile = null) {}

    public function tenantId(): string
    {
        return $this->user->tenant_id;
    }

    /** null = todas */
    public function allowed(string $permission): ?array
    {
        return $this->allowed[$permission] ??= $this->access->allowedOrganizationIds($this->user, $permission, $this->profile);
    }

    public function canOnOrg(string $permission, ?string $orgId): bool
    {
        $allowed = $this->allowed($permission);

        return $allowed === null || ($orgId !== null && in_array($orgId, $allowed, true));
    }

    /** Organizaciones del tenant: id, rut, legal_name, trade_name. */
    public function orgs(): array
    {
        return $this->orgs ??= DB::table('core.organizations')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')
            ->get(['id', 'rut', 'legal_name', 'trade_name'])->all();
    }

    /** Busca una organización por RUT o por nombre. */
    public function findOrg(string $value): ?object
    {
        $key = Rut::key($value);
        $name = Cast::norm($value);
        foreach ($this->orgs() as $o) {
            if (($o->rut && $key !== '' && Rut::key($o->rut) === $key) || Cast::norm($o->legal_name) === $name || ($o->trade_name && Cast::norm($o->trade_name) === $name)) {
                return $o;
            }
        }

        return null;
    }
}
