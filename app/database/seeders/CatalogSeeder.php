<?php

namespace Database\Seeders;

use App\Models\CatalogItem;
use App\Models\CatalogType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Catálogos del sistema: se cargan una vez y cada tenant puede agregar los suyos (salvo donde se indique). */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            'regiones' => ['Regiones de Chile', false, [
                'XV' => 'Arica y Parinacota', 'I' => 'Tarapacá', 'II' => 'Antofagasta', 'III' => 'Atacama', 'IV' => 'Coquimbo', 'V' => 'Valparaíso',
                'RM' => 'Metropolitana de Santiago', 'VI' => "Libertador General Bernardo O'Higgins", 'VII' => 'Maule', 'XVI' => 'Ñuble', 'VIII' => 'Biobío',
                'IX' => 'La Araucanía', 'XIV' => 'Los Ríos', 'X' => 'Los Lagos', 'XI' => 'Aysén del General Carlos Ibáñez del Campo', 'XII' => 'Magallanes y de la Antártica Chilena',
            ]],
            'nacionalidades' => ['Nacionalidades', true, ['Chilena', 'Argentina', 'Boliviana', 'Peruana', 'Colombiana', 'Venezolana', 'Haitiana', 'Ecuatoriana', 'Brasileña',
                'Uruguaya', 'Paraguaya', 'Cubana', 'Dominicana', 'Mexicana', 'Española', 'Estadounidense', 'China', 'Italiana', 'Alemana', 'Francesa', 'Otra']],
            'industrias' => ['Industrias', true, ['Agricultura y ganadería', 'Minería', 'Pesca y acuicultura', 'Manufactura', 'Energía y agua', 'Construcción', 'Comercio',
                'Retail', 'Transporte y logística', 'Hotelería y turismo', 'Tecnología e información', 'Telecomunicaciones', 'Finanzas y seguros', 'Inmobiliaria', 'Servicios profesionales',
                'Educación', 'Salud', 'Administración pública', 'Alimentos y bebidas', 'Otros servicios']],
            'sexo' => ['Sexo', false, ['Femenino', 'Masculino', 'Otro', 'Prefiere no indicar']],
            'cargos' => ['Cargos', true, []],   // cada empresa define los suyos
        ];

        foreach ($types as $key => [$label, $allowTenant, $items]) {
            CatalogType::firstOrCreate(['tenant_id' => null, 'key' => $key], ['label' => $label, 'allow_tenant_items' => $allowTenant]);

            $sort = 0;
            foreach ($items as $code => $text) {
                $code = array_is_list($items) ? Str::slug($text, '_') : (string) $code; // lista simple: el código sale del nombre
                CatalogItem::withoutEvents(fn () => CatalogItem::firstOrCreate(
                    ['tenant_id' => null, 'catalog' => $key, 'code' => $code],
                    ['label' => $text, 'sort' => $sort++],
                ));
            }
        }
    }
}
