<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Avatar;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Sirve imágenes de avatar. Rutas con URL firmada (middleware signed): un <img> no puede enviar el token. */
class MediaController extends Controller
{
    public function avatar(string $avatar): Response
    {
        $a = Avatar::where('is_active', true)->whereHas('collection', fn ($q) => $q->where('is_active', true))->findOrFail($avatar);

        return $this->file($a->path, $a->mime);
    }

    public function userAvatar(string $user): Response
    {
        $u = User::findOrFail($user);
        abort_unless($u->avatar_path, 404);

        return $this->file($u->avatar_path, 'image/webp');
    }

    private function file(string $path, string $mime): Response
    {
        abort_unless(Storage::disk('local')->exists($path), 404);

        // El CSP con sandbox impide que un SVG ejecute scripts aunque alguien abra la URL directamente.
        return Storage::disk('local')->response($path, null, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
