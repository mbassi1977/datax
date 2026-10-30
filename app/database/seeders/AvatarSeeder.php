<?php

namespace Database\Seeders;

use App\Models\Avatar;
use App\Models\AvatarCollection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Colección del sistema "Clásicos": 12 avatares SVG genéricos, disponibles para todas las empresas. */
class AvatarSeeder extends Seeder
{
    private const COLORS = ['#1f5eff', '#0e9f6e', '#d97706', '#dc2626', '#7c3aed', '#db2777', '#0891b2', '#4d7c0f', '#b45309', '#475569', '#9333ea', '#0f766e'];
    private const SKIN = ['#f2c9a5', '#e0a57a', '#c68863', '#8d5a3b', '#f7d9bf', '#a8734f'];
    private const HAIR = ['#2b2118', '#5a3a22', '#111827', '#a16207', '#6b7280', '#7f1d1d'];

    public function run(): void
    {
        $collection = AvatarCollection::firstOrCreate(
            ['tenant_id' => null, 'name' => 'Clásicos'],
            ['description' => 'Avatares genéricos incluidos con HRX.', 'is_global' => true],
        );
        if ($collection->avatars()->exists()) {
            return;
        }

        foreach (self::COLORS as $i => $bg) {
            $id = (string) Str::uuid();
            $path = "avatars/gallery/{$collection->id}/{$id}.svg";
            Storage::disk('local')->put($path, $this->svg($bg, self::SKIN[$i % 6], self::HAIR[($i * 5) % 6], $i % 4));
            Avatar::create(['id' => $id, 'collection_id' => $collection->id, 'name' => sprintf('Avatar %02d', $i + 1), 'path' => $path, 'mime' => 'image/svg+xml',
                'size' => Storage::disk('local')->size($path), 'sort' => $i]);
        }
    }

    /** Silueta simple: fondo de color, hombros, cabeza y un peinado entre cuatro variantes. */
    private function svg(string $bg, string $skin, string $hair, int $style): string
    {
        $hairShape = match ($style) {
            0 => "<path d='M70 100c0-34 20-50 58-50s58 16 58 50c-14-18-34-26-58-26s-44 8-58 26z' fill='$hair'/>",                                  // corto
            1 => "<path d='M64 112c-4-46 22-66 64-66s68 20 64 66c-2 28-8 52-8 52l-20-30c0-30-14-44-36-44s-36 14-36 44l-20 30s-6-24-8-52z' fill='$hair'/>", // largo
            2 => "<ellipse cx='128' cy='66' rx='62' ry='26' fill='$hair'/>",                                                                          // casquete
            default => "<circle cx='128' cy='52' r='22' fill='$hair'/><path d='M72 104c4-30 26-44 56-44s52 14 56 44c-16-14-34-20-56-20s-40 6-56 20z' fill='$hair'/>", // rodete
        };

        return "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 256 256' width='256' height='256'>"
            ."<rect width='256' height='256' fill='$bg'/>"
            ."<path d='M28 256c0-52 44-86 100-86s100 34 100 86z' fill='#ffffff' fill-opacity='.92'/>"
            ."<rect x='108' y='132' width='40' height='40' rx='14' fill='$skin'/>"
            ."<ellipse cx='128' cy='104' rx='46' ry='52' fill='$skin'/>"
            .$hairShape
            ."<circle cx='111' cy='108' r='4.5' fill='#1f2937'/><circle cx='145' cy='108' r='4.5' fill='#1f2937'/>"
            ."<path d='M114 128c8 8 20 8 28 0' stroke='#1f2937' stroke-width='4' stroke-linecap='round' fill='none'/></svg>";
    }
}
