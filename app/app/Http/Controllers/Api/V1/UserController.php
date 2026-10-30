<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends ScopedResourceController
{
    protected function model(): string { return User::class; }
    protected function prefix(): string { return 'usuarios'; }
    protected function searchable(): array { return ['email']; }
    protected function orgRequired(): bool { return false; } // organización principal opcional

    protected function rules(string $tenantId, ?string $orgId, ?string $ignoreId): array
    {
        return [
            'email' => [$ignoreId ? 'sometimes' : 'required', 'email', $this->uniqueInTenant('security.users', 'email', $tenantId, $ignoreId)],
            'password' => [$ignoreId ? 'sometimes' : 'required', app(\App\Support\Settings\SettingsService::class)->passwordRule($tenantId, $orgId)],
            'person_id' => ['nullable', 'uuid', $this->existsInTenant('master.people', $tenantId)],
            'status' => ['sometimes', 'in:active,suspended,blocked'],
        ];
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $response = parent::update($request, $id);

        // Cambio de clave o desactivación: se cierran las sesiones abiertas del usuario.
        if ($request->filled('password') || ($request->has('status') && $request->input('status') !== 'active')) {
            User::find($id)?->tokens()->delete();
        }

        return $response;
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($id === $request->user()->id) {
            abort(422, 'No puede eliminar su propio usuario.');
        }
        $response = parent::destroy($request, $id);
        User::withTrashed()->find($id)?->tokens()->delete();

        return $response;
    }
}
