<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Avatar;
use App\Models\FieldAuditEvidence;
use App\Models\Setting;
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

    public function auditEvidence(string $evidence): Response
    {
        $e = FieldAuditEvidence::findOrFail($evidence);

        return $this->file($e->path, $e->mime);
    }

    public function selloutProduct(string $product): Response
    {
        $p = \App\Models\SelloutProduct::findOrFail($product);
        abort_unless($p->image_path, 404);

        return $this->file($p->image_path, $p->image_mime ?: 'image/png');
    }

    public function selloutChainLogo(string $chain): Response
    {
        $c = \App\Models\SelloutChain::findOrFail($chain);
        abort_unless($c->logo_path, 404);

        return $this->file($c->logo_path, $c->logo_mime ?: 'image/png');
    }

    public function selloutBannerLogo(string $banner): Response
    {
        $b = \App\Models\SelloutBanner::findOrFail($banner);
        abort_unless($b->logo_path, 404);

        return $this->file($b->logo_path, $b->logo_mime ?: 'image/png');
    }

    public function selloutPhoto(string $photo): Response
    {
        $f = \App\Models\SelloutPointPhoto::findOrFail($photo);

        return $this->file($f->path, $f->mime);
    }

    public function selloutResponse(string $req): Response
    {
        $r = \App\Models\SelloutRequest::findOrFail($req);
        abort_unless($r->response_photo, 404);

        return $this->file($r->response_photo, $r->response_mime ?: 'image/jpeg');
    }

    public function logo(string $setting): Response
    {
        $s = Setting::where('key', 'brand.logo')->findOrFail($setting);
        abort_unless(is_array($s->value) && isset($s->value['path']), 404);

        return $this->file($s->value['path'], $s->value['mime'] ?? 'image/webp');
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
