<?php

namespace App\Support\Avatars;

use App\Models\Avatar;
use App\Models\AvatarCollection;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/** Avatares: galerías por empresa, fotos propias (recortadas y reducidas) y URLs firmadas para mostrarlos. */
class AvatarService
{
    public const USER_MAX_KB = 4096;
    public const GALLERY_MAX_KB = 1024;
    private const RASTER = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** URL firmada (relativa) válida unas horas; estable dentro de la misma hora para que el navegador la reutilice. */
    private function signed(string $route, array $params): string
    {
        return URL::temporarySignedRoute($route, now()->startOfHour()->addHours(12), $params, absolute: false);
    }

    public function galleryUrl(Avatar $avatar): string
    {
        return $this->signed('media.avatar', ['avatar' => $avatar->id, 'v' => $avatar->updated_at?->timestamp]);
    }

    public function userUrl(User $user): ?string
    {
        if ($user->avatar_path) {
            return $this->signed('media.user-avatar', ['user' => $user->id, 'v' => $user->avatar_updated_at?->timestamp]);
        }

        return $user->avatar_id ? $this->signed('media.avatar', ['avatar' => $user->avatar_id, 'v' => $user->avatar_updated_at?->timestamp]) : null;
    }

    /** Colecciones activas que el usuario puede usar: del sistema o de su tenant, globales o asignadas a su empresa. */
    public function collectionsFor(User $user)
    {
        return AvatarCollection::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $user->tenant_id))
            ->where(fn ($q) => $q->where('is_global', true)
                ->orWhereIn('id', DB::table('core.avatar_collection_organization')->select('collection_id')->where('organization_id', $user->organization_id ?? '00000000-0000-0000-0000-000000000000')))
            ->with(['avatars' => fn ($q) => $q->where('is_active', true)])
            ->orderByRaw('tenant_id is null desc')->orderBy('name')->get();
    }

    public function canUse(User $user, Avatar $avatar): bool
    {
        return $avatar->is_active && $this->collectionsFor($user)->contains('id', $avatar->collection_id);
    }

    public function chooseFromGallery(User $user, Avatar $avatar): void
    {
        $this->deleteOwnFile($user);
        $user->forceFill(['avatar_id' => $avatar->id, 'avatar_path' => null, 'avatar_updated_at' => now()])->save();
    }

    public function remove(User $user): void
    {
        $this->deleteOwnFile($user);
        $user->forceFill(['avatar_id' => null, 'avatar_path' => null, 'avatar_updated_at' => now()])->save();
    }

    /** Foto propia: se valida que sea una imagen real, se recorta al centro (cuadrada) y se reduce a 256 px en WebP. */
    public function upload(User $user, UploadedFile $file): void
    {
        $path = "avatars/users/{$user->id}/".Str::uuid().'.webp';
        Storage::disk('local')->put($path, $this->normalize($file->getRealPath()));
        $this->deleteOwnFile($user);
        $user->forceFill(['avatar_id' => null, 'avatar_path' => $path, 'avatar_updated_at' => now()])->save();
    }

    /** Imagen de galería: raster normalizado a 256 px; SVG permitido (se sirve aislado, ver MediaController). */
    public function storeGalleryFile(AvatarCollection $collection, UploadedFile $file, ?string $name = null): Avatar
    {
        $id = (string) Str::uuid();
        $isSvg = $this->isSvg($file);
        $ext = $isSvg ? 'svg' : 'webp';
        $path = "avatars/gallery/{$collection->id}/{$id}.{$ext}";

        Storage::disk('local')->put($path, $isSvg ? file_get_contents($file->getRealPath()) : $this->normalize($file->getRealPath()));

        return Avatar::create([
            'id' => $id, 'collection_id' => $collection->id, 'path' => $path,
            'name' => $name ?: Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 80, ''),
            'mime' => $isSvg ? 'image/svg+xml' : 'image/webp', 'size' => Storage::disk('local')->size($path),
            'sort' => (int) $collection->avatars()->max('sort') + 1,
        ]);
    }

    public function isSvg(UploadedFile $file): bool
    {
        return strtolower($file->getClientOriginalExtension()) === 'svg' && str_contains(substr((string) file_get_contents($file->getRealPath(), false, null, 0, 2048), 0), '<svg');
    }

    private function deleteOwnFile(User $user): void
    {
        $user->avatar_path && Storage::disk('local')->delete($user->avatar_path);
    }

    private function normalize(string $file): string
    {
        $info = @getimagesize($file);
        if (! $info || ! isset(self::RASTER[$info['mime']])) {
            throw new RuntimeException('El archivo no es una imagen válida (use JPG, PNG o WebP).');
        }
        if ($info[0] * $info[1] > 40_000_000) {
            throw new RuntimeException('La imagen es demasiado grande.');
        }
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('El servidor no tiene soporte de imágenes (instale php-gd).');
        }

        $src = @imagecreatefromstring((string) file_get_contents($file));
        if (! $src) {
            throw new RuntimeException('No se pudo leer la imagen.');
        }
        [$w, $h] = [imagesx($src), imagesy($src)];
        $side = min($w, $h);
        $dst = imagecreatetruecolor(256, 256);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 256, 256, $side, $side);

        ob_start();
        imagewebp($dst, null, 86);   // reencodificar elimina metadatos (EXIF/GPS) y cualquier carga oculta
        $bin = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return $bin;
    }
}
