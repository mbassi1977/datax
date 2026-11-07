<?php

namespace App\Support\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Guarda imágenes subidas: valida que lo sean, las reduce y las reencodifica (elimina EXIF y cargas ocultas). */
class ImageStore
{
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @return array{path:string,mime:string,size:int} */
    public static function store(UploadedFile $file, string $dir, int $maxSide = 1600, bool $alpha = false): array
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
        $k = min(1, $maxSide / max($w, $h));
        $dst = imagecreatetruecolor(max(1, (int) round($w * $k)), max(1, (int) round($h * $k)));
        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
        ob_start();
        $alpha ? imagepng($dst, null, 7) : imagejpeg($dst, null, 84);
        $bin = (string) ob_get_clean();
        $path = trim($dir, '/').'/'.Str::uuid().($alpha ? '.png' : '.jpg');
        Storage::disk('local')->put($path, $bin);

        return ['path' => $path, 'mime' => $alpha ? 'image/png' : 'image/jpeg', 'size' => strlen($bin)];
    }
}
