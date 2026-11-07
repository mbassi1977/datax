<?php

namespace App\Imports;

use App\Models\SelloutPoint;
use App\Models\SelloutProduct;
use App\Support\Sellout\CommuneLocator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ventas por sala y producto. Acepta los formatos de las cadenas tal como vienen (columnas con distintos nombres, fechas diarias o meses).
 * Las filas se suman por sala + producto + período y se escriben en bloque al final; volver a cargar un período lo reemplaza.
 */
class SelloutSalesTarget extends SelloutTarget
{
    public function key(): string { return 'sellout_sales'; }
    public function label(): string { return 'Sell-out · Ventas'; }
    public function maxRows(): int { return 400000; }
    public function maxKb(): int { return 61440; }
    public function multiSheet(): bool { return true; }

    public function fields(): array
    {
        return [
            'chain' => $this->chainField(),
            'code' => $this->codeField(),
            'name' => $this->nameField(),
            'date' => ['label' => 'Fecha o período', 'required' => true, 'required_unless' => 'month', 'synonyms' => ['fecha', 'fecha date', 'periodo', 'date', 'dia'], 'example' => '02/01/2025 o Enero 2025'],
            'month' => ['label' => 'Mes', 'synonyms' => ['mes'], 'example' => 'Ene'],
            'year' => ['label' => 'Año', 'synonyms' => ['ano', 'anio', 'year'], 'example' => '2025'],
            'product' => ['label' => 'Producto', 'required' => true, 'synonyms' => ['articulo desc', 'desc sku', 'descripcion producto', 'item desc 1', 'sku desc', 'producto', 'articulo', 'descripcion'], 'example' => 'Cerveza Entrenubes 470cc'],
            'units' => ['label' => 'Unidades', 'required' => true, 'synonyms' => ['venta unidades', 'venta en unidades', 'vta unid', 'pos qty', 'unidades', 'cantidad', 'unid'], 'example' => '12'],
            'sales' => ['label' => 'Venta neta ($)', 'required' => true, 'synonyms' => ['venta neta', 'venta neta', 'vta pub s iva', 'pos sales', 'venta', 'monto', 'venta sin iva'], 'example' => '21500'],
            'cost' => ['label' => 'Costo ($)', 'synonyms' => ['costo venta', 'venta costo', 'vta costo s iva', 'pos cost', 'costo'], 'example' => '15000'],
            'channel' => ['label' => 'Canal', 'synonyms' => ['canal de venta', 'canal'], 'example' => 'SALA'],
        ];
    }

    public function extraOptions(): array
    {
        return parent::extraOptions() + [
            1 => ['key' => 'granularity', 'type' => 'select', 'label' => 'Cómo guardar el período', 'choices' => [['v' => 'month', 'l' => 'Por mes (suma los días y semanas)'], ['v' => 'week', 'l' => 'Por semana (lunes a domingo)']]],
            2 => ['key' => 'create_points', 'type' => 'select', 'label' => 'Salas que no existen', 'choices' => [['v' => '1', 'l' => 'Crearlas automáticamente'], ['v' => '0', 'l' => 'Rechazar la fila']]],
        ];
    }

    private function products(ImportContext $ctx): array
    {
        return $ctx->cache['products'] ??= SelloutProduct::where('organization_id', $this->org($ctx))->where('active', true)->get()
            ->map(fn ($p) => ['id' => $p->id, 'terms' => collect(array_merge([$p->name], $p->aliases ?? []))->map(fn ($a) => Cast::norm((string) $a))->filter()->unique()->values()->all()])->all();
    }

    private function product(ImportContext $ctx, string $text): ?string
    {
        $n = Cast::norm($text);
        $best = null;
        $bestLen = 0;
        foreach ($this->products($ctx) as $p) {
            foreach ($p['terms'] as $t) {
                if (($n === $t || str_contains(' '.$n.' ', ' '.$t.' ') || str_contains($n, $t)) && strlen($t) > $bestLen) {
                    [$best, $bestLen] = [$p['id'], strlen($t)];
                }
            }
        }

        return $best;
    }

    public function normalize(array $raw, ImportContext $ctx): array
    {
        $data = [];
        $errors = [];
        $data['chain'] = $this->chain($ctx, $raw, $errors);
        if ($v = $this->required($raw, 'code', $errors)) {
            $data['code'] = $v;
        }
        $data['name'] = trim((string) ($raw['name'] ?? ''));

        $m = self::monthStart($raw['date'] ?? '', $raw['month'] ?? '', $raw['year'] ?? '');
        $d = trim((string) ($raw['date'] ?? ''));
        if (($extra = ($ctx->options['extra']['granularity'] ?? 'month')) === 'week' && $d !== '' && ($iso = Cast::date($d))) {
            $data['period_start'] = Carbon::parse($iso)->startOfWeek()->toDateString();
            $data['period_type'] = 'W';
        } elseif ($m) {
            $data['period_start'] = $m;
            $data['period_type'] = 'M';
        } else {
            $errors['date'] = 'Fecha o período no reconocido (use dd/mm/aaaa, «Enero 2025» o mes y año por separado).';
        }

        $u = CommuneLocator::number($raw['units'] ?? '');
        $u !== null ? $data['units'] = $u : $errors['units'] = 'Las unidades deben ser un número.';
        $s = CommuneLocator::number($raw['sales'] ?? '');
        $s !== null ? $data['sales'] = $s : $errors['sales'] = 'La venta debe ser un número.';
        $c = trim((string) ($raw['cost'] ?? '')) === '' ? null : CommuneLocator::number($raw['cost']);
        $data['cost'] = $c;
        $data['channel'] = Str::upper(mb_substr(trim((string) ($raw['channel'] ?? '')) ?: 'SALA', 0, 30));

        if ($pt = trim((string) ($raw['product'] ?? ''))) {
            $pid = $this->product($ctx, $pt);
            $pid ? $data['product_id'] = $pid : $errors['product'] = "Producto sin equivalencia en el catálogo del cliente: «{$pt}». Agregue su nombre como alias en Catálogo.";
        } else {
            $errors['product'] = 'Es obligatorio.';
        }

        if ($errors === []) {
            $this->resolvePoint($ctx, $data, $errors);
        }

        return [$data, $errors];
    }

    /** Sala de la fila; si no existe y está permitido, se crea al cargar (en simulación solo se anota). */
    private function resolvePoint(ImportContext $ctx, array &$data, array &$errors): void
    {
        $key = $data['chain'].'|'.mb_strtolower($data['code']);
        if (isset($ctx->cache['points'][$key])) {
            $data['point_id'] = $ctx->cache['points'][$key];

            return;
        }
        $p = $this->findPoint($ctx, (string) $data['chain'], (string) $data['code']);
        if ($p) {
            $data['point_id'] = $ctx->cache['points'][$key] = $p->id;

            return;
        }
        if (($ctx->options['extra']['create_points'] ?? '1') === '0') {
            $errors['code'] = "La sala «{$data['code']}» no existe. Cárguela primero en Salas.";

            return;
        }
        if (! $ctx->commit) {
            $data['point_id'] = $ctx->cache['points'][$key] = 'sim:'.$key;
            $ctx->warnings[] = ['row' => $ctx->currentRow, 'message' => "Se creará la sala «{$data['name']}» ({$data['code']})."];

            return;
        }
        $name = $data['name'] !== '' ? $data['name'] : 'Sala '.$data['code'];
        $g = $this->geo(null, $name, $data['code']);
        $pt = SelloutPoint::create(['tenant_id' => $ctx->tenantId(), 'organization_id' => $this->org($ctx), 'chain_id' => $data['chain'], 'code' => $data['code'], 'name' => $name,
            'commune' => $g['commune'], 'region' => $g['region'], 'latitude' => $g['lat'], 'longitude' => $g['lon'], 'geo_quality' => $g['quality'], 'created_by' => $ctx->user->id]);
        $data['point_id'] = $ctx->cache['points'][$key] = $pt->id;
    }

    public function findExisting(ImportContext $ctx, array $data): ?Model { return null; }

    public function dedupeKey(array $data): ?string { return null; }

    public function create(ImportContext $ctx, array $data): Model
    {
        $k = implode('|', [$data['point_id'], $data['product_id'], $data['period_start'], $data['period_type'], $data['channel']]);
        $b = &$ctx->cache['buffer'][$k];
        $b ??= ['point_id' => $data['point_id'], 'product_id' => $data['product_id'], 'period_start' => $data['period_start'], 'period_type' => $data['period_type'], 'channel' => $data['channel'], 'units' => 0.0, 'sales' => 0.0, 'cost' => null];
        $b['units'] += $data['units'];
        $b['sales'] += $data['sales'];
        if ($data['cost'] !== null) {
            $b['cost'] = ($b['cost'] ?? 0) + $data['cost'];
        }

        return new SelloutPoint();
    }

    public function finalize(ImportContext $ctx): void
    {
        $org = $this->org($ctx);
        $now = now();
        $rows = [];
        foreach (array_values($ctx->cache['buffer'] ?? []) as $r) {
            $rows[] = $r + ['id' => (string) Str::uuid(), 'tenant_id' => $ctx->tenantId(), 'organization_id' => $org, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('sellout.sales')->upsert($chunk, ['point_id', 'product_id', 'period_start', 'period_type', 'channel'], ['units', 'sales', 'cost', 'updated_at']);
        }
        $ctx->cache['buffer'] = [];
    }
}
