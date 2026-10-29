<?php

namespace Tests\Unit;

use App\Imports\NameSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameSplitterTest extends TestCase
{
    public static function cases(): array
    {
        // [entrada, formato, nombres, paterno, materno, confianza]
        return [
            'coma' => ['Pérez Soto, Ana María', 'auto', 'Ana María', 'Pérez', 'Soto', 'high'],
            'coma un apellido' => ['Pérez, Ana', 'auto', 'Ana', 'Pérez', null, 'high'],
            'nombres primero' => ['Ana María Pérez Soto', 'auto', 'Ana María', 'Pérez', 'Soto', 'high'],
            'apellidos primero en mayúsculas' => ['PÉREZ SOTO ANA MARÍA', 'auto', 'Ana María', 'Pérez', 'Soto', 'high'],
            'apellido compuesto' => ['Juan Carlos De la Fuente Muñoz', 'auto', 'Juan Carlos', 'De la Fuente', 'Muñoz', 'high'],
            'nombre con partícula' => ['María de los Ángeles Pérez Soto', 'auto', 'María de los Ángeles', 'Pérez', 'Soto', 'high'],
            'dos palabras' => ['Pedro Pérez', 'auto', 'Pedro', 'Pérez', null, 'high'],
            'dos palabras invertidas' => ['Pérez Pedro', 'auto', 'Pedro', 'Pérez', null, 'high'],
            'tres, un nombre y dos apellidos' => ['Ana Pérez Soto', 'auto', 'Ana', 'Pérez', 'Soto', 'high'],
            'tres, dos nombres y un apellido' => ['María José Soto', 'auto', 'María José', 'Soto', null, 'high'],
            'apellido solo y nombres' => ['Pérez Ana María', 'auto', 'Ana María', 'Pérez', null, 'high'],
            'minúsculas' => ['juan pérez soto', 'auto', 'Juan', 'Pérez', 'Soto', 'high'],
            'apellidos poco comunes' => ['Ana Quilodrán Huenchumil', 'auto', 'Ana', 'Quilodrán', 'Huenchumil', 'medium'],
            'orden forzado' => ['Soto Pérez Ana', 'surnames_first', 'Ana', 'Soto', 'Pérez', 'medium'],
        ];
    }

    #[DataProvider('cases')]
    public function test_split(string $in, string $format, ?string $names, ?string $pat, ?string $mat, string $confidence): void
    {
        $r = NameSplitter::split($in, $format);
        $this->assertSame([$names, $pat, $mat], [$r['first_name'], $r['last_name'], $r['second_last_name']], $in);
        $this->assertSame($confidence, $r['confidence'], "confianza de «{$in}»");
    }

    public function test_unknown_words_are_low_confidence_never_silently_trusted(): void
    {
        $this->assertSame('low', NameSplitter::split('Zxqwv Plmko Rtyu Asdf')['confidence']);
        $this->assertSame('low', NameSplitter::split('Ana')['confidence']);
        $this->assertNull(NameSplitter::split('Ana')['last_name']);
        $this->assertSame('low', NameSplitter::split('Zxqwv Plmko Rtyu')['confidence']);
    }

    public function test_mixed_case_is_preserved_and_single_case_is_normalized(): void
    {
        $this->assertSame('McKenzie', NameSplitter::split('Pedro McKenzie Soto')['last_name']);
        $this->assertSame('Del Río', NameSplitter::split('PEDRO DEL RÍO SOTO')['last_name']);
    }
}
