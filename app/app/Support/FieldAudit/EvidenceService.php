<?php

namespace App\Support\FieldAudit;

use App\Models\FieldAudit;
use App\Models\FieldAuditEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/** Evidencias de auditoría: se valida que sean imágenes reales, se reducen (máx. 1600 px) y se sirven con URL firmada. */
class EvidenceService
{
    private const MAX_SIDE = 1600;
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @return array{path:string,mime:string,size:int} */
    public function store(FieldAudit $audit, UploadedFile $file, string $kind): array
    {
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info['mime'], self::MIMES, true)) {
            throw new RuntimeException('El archivo no es una imagen válida (JPG, PNG o WebP).');
        }
        if ($info[0] * $info[1] > 60_000_000) {
            throw new RuntimeException('La imagen es demasiado grande.');
        }
        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $src) {
            throw new RuntimeException('No se pudo leer la imagen.');
        }

        [$w, $h] = [imagesx($src), imagesy($src)];
        $k = min(1, self::MAX_SIDE / max($w, $h));
        $dst = imagecreatetruecolor(max(1, (int) round($w * $k)), max(1, (int) round($h * $k)));
        $png = $kind === 'signature';                                        // las firmas conservan la transparencia
        if ($png) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);

        ob_start();
        $png ? imagepng($dst, null, 6) : imagejpeg($dst, null, 84);          // se reencodifica: elimina EXIF y cargas ocultas
        $bin = (string) ob_get_clean();
        $ext = $png ? 'png' : 'jpg';
        $path = "fieldaudit/{$audit->tenant_id}/{$audit->id}/".Str::uuid().".{$ext}";
        Storage::disk('local')->put($path, $bin);

        return ['path' => $path, 'mime' => $png ? 'image/png' : 'image/jpeg', 'size' => strlen($bin)];
    }

    public function delete(FieldAuditEvidence $e): void
    {
        Storage::disk('local')->delete($e->path);
    }

    public function url(FieldAuditEvidence $e): string
    {
        return URL::temporarySignedRoute('media.audit-evidence', now()->startOfHour()->addHours(12), ['evidence' => $e->id], absolute: false);
    }
}
