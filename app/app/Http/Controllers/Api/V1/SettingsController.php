<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Setting;
use App\Support\Access\AccessService;
use App\Support\Settings\SettingsRegistry;
use App\Support\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Parámetros por tenant y por organización (marca, regional, política de contraseñas, MFA).
 * Ver: cualquier perfil con configuracion.ver. Editar el nivel tenant exige alcance global; el de una organización, alcance sobre ella.
 */
class SettingsController extends Controller
{
    public function __construct(private AccessService $access, private SettingsService $settings) {}

    public function show(Request $request): JsonResponse
    {
        $orgId = $this->scope($request, 'configuracion.ver');
        $tenant = $request->user()->tenant_id;
        $own = $this->settings->rawAt($tenant, $orgId);
        $above = $orgId ? $this->settings->effective($tenant, null) : SettingsRegistry::defaults();

        $logoOwn = Setting::where('tenant_id', $tenant)->where('key', 'brand.logo')->when($orgId, fn ($q) => $q->where('organization_id', $orgId), fn ($q) => $q->whereNull('organization_id'))->first();
        $logoAbove = $orgId ? $this->settings->logoRow($tenant, null) : null;

        return response()->json([
            'organization_id' => $orgId,
            'definitions' => collect(SettingsRegistry::all())->map(fn ($d, $key) => $d + ['key' => $key, 'value' => $own[$key] ?? null, 'inherited' => $above[$key]])->values(),
            'logo' => ['url' => $this->settings->logoUrl($logoOwn), 'inherited_url' => $this->settings->logoUrl($logoAbove)],
        ]);
    }

    /** body: { organization_id?: uuid, values: { clave: valor | null } } — null elimina el valor propio y se hereda. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['organization_id' => ['nullable', 'uuid'], 'values' => ['required', 'array', 'min:1']]);
        $orgId = $this->scope($request, 'configuracion.editar', $data['organization_id'] ?? null);
        $tenant = $request->user()->tenant_id;

        $before = $this->settings->rawAt($tenant, $orgId);
        $clean = [];
        foreach ($data['values'] as $key => $value) {
            $clean[$key] = $value === null ? null : SettingsRegistry::validate($key, $value);
        }
        DB::transaction(function () use ($clean, $tenant, $orgId, $request) {
            foreach ($clean as $key => $value) {
                $this->settings->set($tenant, $orgId, $key, $value, $request->user()->id);
            }
        });
        AuditEvent::record('settings_changed', null, array_intersect_key($before, $clean), $clean + ['organization_id' => $orgId]);

        return $this->show($request->merge(['organization_id' => $orgId]));
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:1024'], 'organization_id' => ['nullable', 'uuid']]);
        $orgId = $this->scope($request, 'configuracion.editar');
        $tenant = $request->user()->tenant_id;
        $file = $request->file('file');

        try {
            [$bin, $ext, $mime] = $this->process($file);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        $path = 'logos/'.$tenant.'/'.($orgId ?? 'tenant').'/'.Str::uuid().'.'.$ext;
        Storage::disk('local')->put($path, $bin);

        $row = Setting::where('tenant_id', $tenant)->where('key', 'brand.logo')->when($orgId, fn ($q) => $q->where('organization_id', $orgId), fn ($q) => $q->whereNull('organization_id'))->first();
        $row?->value && Storage::disk('local')->delete($row->value['path'] ?? '');
        $row ? $row->update(['value' => ['path' => $path, 'mime' => $mime], 'updated_by' => $request->user()->id])
             : Setting::create(['tenant_id' => $tenant, 'organization_id' => $orgId, 'key' => 'brand.logo', 'value' => ['path' => $path, 'mime' => $mime], 'updated_by' => $request->user()->id]);
        AuditEvent::record('settings_changed', null, null, ['brand.logo' => 'updated', 'organization_id' => $orgId]);

        return $this->show($request->merge(['organization_id' => $orgId]));
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        $orgId = $this->scope($request, 'configuracion.editar');
        $row = Setting::where('tenant_id', $request->user()->tenant_id)->where('key', 'brand.logo')->when($orgId, fn ($q) => $q->where('organization_id', $orgId), fn ($q) => $q->whereNull('organization_id'))->first();
        if ($row) {
            Storage::disk('local')->delete($row->value['path'] ?? '');
            $row->delete();
            AuditEvent::record('settings_changed', null, ['brand.logo' => 'set'], ['brand.logo' => null, 'organization_id' => $orgId]);
        }

        return response()->json(null, 204);
    }

    /** Resuelve y autoriza el ámbito pedido (tenant u organización). */
    private function scope(Request $request, string $permission, ?string $explicit = null): ?string
    {
        $orgId = $explicit ?? $request->input('organization_id', $request->query('organization_id'));
        $user = $request->user();

        if ($orgId === null || $orgId === '') {
            // Ver el nivel tenant lo puede cualquiera con configuracion.ver; editarlo exige alcance global.
            abort_if($permission === 'configuracion.editar' && $this->access->allowedOrganizationIds($user, $permission, $request->header('X-Profile')) !== null, 403, 'Editar la configuración general requiere alcance global.');

            return null;
        }
        abort_unless(DB::table('core.organizations')->where('tenant_id', $user->tenant_id)->where('id', $orgId)->whereNull('deleted_at')->exists(), 404, 'Organización inexistente.');
        abort_unless($this->access->canOnOrganization($user, $permission, $orgId, $request->header('X-Profile')), 403, 'Fuera de su alcance.');

        return $orgId;
    }

    /** Logo: raster reducido (máx. 512 px, conserva transparencia) o SVG (se sirve aislado con CSP sandbox). */
    private function process(UploadedFile $file): array
    {
        if (strtolower($file->getClientOriginalExtension()) === 'svg' && str_contains((string) file_get_contents($file->getRealPath(), false, null, 0, 2048), '<svg')) {
            return [file_get_contents($file->getRealPath()), 'svg', 'image/svg+xml'];
        }
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new RuntimeException('El logo debe ser PNG, JPG, WebP o SVG.');
        }
        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $src) {
            throw new RuntimeException('No se pudo leer la imagen.');
        }
        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1, 512 / max($w, $h));
        $dst = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
        ob_start();
        imagewebp($dst, null, 90);

        return [(string) ob_get_clean(), 'webp', 'image/webp'];
    }
}
