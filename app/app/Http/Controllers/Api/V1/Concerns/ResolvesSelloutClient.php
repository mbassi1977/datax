<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Support\Access\AccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** El módulo trabaja siempre sobre un cliente (marca): la organización indicada debe estar dentro del alcance del usuario. */
trait ResolvesSelloutClient
{
    protected function client(Request $request, string $permission): string
    {
        /** @var AccessService $access */
        $access = $this->access;
        $allowed = $access->allowedOrganizationIds($request->user(), $permission, $request->header('X-Profile'));
        $org = $request->query('organization_id') ?? $request->input('organization_id');
        if (! $org && $allowed !== null && count($allowed) === 1) {
            $org = $allowed[0];
        }
        abort_unless($org, 422, 'Indique el cliente (organization_id).');
        abort_unless($allowed === null || in_array($org, $allowed, true), 403, 'Fuera de su alcance.');
        abort_unless(DB::table('core.organizations')->where('tenant_id', $request->user()->tenant_id)->where('id', $org)->whereNull('deleted_at')->exists(), 404, 'El cliente no existe.');

        return $org;
    }

    /** Verifica permiso + alcance sobre la organización de un registro. */
    protected function authorizeOn(Request $request, string $permission, string $orgId): void
    {
        abort_unless($this->access->canOnOrganization($request->user(), $permission, $orgId, $request->header('X-Profile')), 403, 'Fuera de su alcance.');
    }
}
