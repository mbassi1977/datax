<?php

namespace App\Imports;

use App\Models\SelloutChain;
use App\Models\SelloutPoint;
use App\Support\Sellout\CommuneLocator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Base de las cargas del módulo Sell-out: el cliente (marca) es la organización elegida en el asistente. */
abstract class SelloutTarget extends ImportTarget
{
    public const MONTHS = ['ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'sep' => 9, 'set' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12];

    public function permission(): string { return 'sellout'; }

    protected function chainField(): array
    {
        return ['label' => 'Cadena', 'synonyms' => ['cliente', 'cadena', 'retail', 'canal retail'], 'example' => 'Cencosud'];
    }

    protected function codeField(): array
    {
        return ['label' => 'Código de sala', 'required' => true, 'synonyms' => ['codigo', 'cod local', 'local id', 'cod localfisico', 'store nbr', 'codigo local', 'id local', 'cod sala'], 'example' => 'J501'];
    }

    protected function nameField(): array
    {
        return ['label' => 'Nombre de la sala', 'synonyms' => ['local', 'local desc', 'desc localfisico', 'descripcion local', 'store name', 'nombre local', 'sala', 'nombre sala'], 'example' => 'Jumbo Bilbao'];
    }

    public function extraOptions(): array
    {
        return [['key' => 'chain_id', 'type' => 'chain', 'label' => 'Cadena (si el archivo no trae una columna de cadena)']];
    }

    protected function org(ImportContext $ctx): ?string
    {
        return $ctx->options['organization_id'] ?? null;
    }

    public function denyCreate(ImportContext $ctx, array $data): ?string
    {
        return $ctx->canOnOrg('sellout.crear', $this->org($ctx)) ? null : 'El cliente está fuera de su alcance.';
    }

    public function denyUpdate(ImportContext $ctx, Model $existing, array $data): ?string
    {
        return $ctx->canOnOrg('sellout.editar', $existing->organization_id) ? null : 'El registro existente está fuera de su alcance.';
    }

    /** Cadena de la fila: su columna o la elegida en el asistente. En simulación una cadena nueva se marca «new:Nombre». */
    protected function chain(ImportContext $ctx, array $raw, array &$errors): ?string
    {
        $org = $this->org($ctx);
        if (! $org) {
            $errors['*'] = 'Elija el cliente (marca) en las opciones de la carga.';

            return null;
        }
        $map = $ctx->cache['chains'] ??= SelloutChain::where('organization_id', $org)->get()->mapWithKeys(fn ($c) => [Cast::norm($c->name) => $c->id])->all();
        $name = trim((string) ($raw['chain'] ?? ''));
        if ($name === '') {
            $id = $ctx->options['extra']['chain_id'] ?? null;
            if (! $id || ! in_array($id, $map, true)) {
                $errors['chain'] = 'Indique la cadena (columna o «Cadena» en las opciones).';

                return null;
            }

            return $id;
        }
        $k = Cast::norm($name);
        if (isset($map[$k])) {
            return $map[$k];
        }
        if ($ctx->commit) {
            $c = SelloutChain::create(['tenant_id' => $ctx->tenantId(), 'organization_id' => $org, 'name' => Str::title(mb_strtolower($name))]);
            $ctx->cache['chains'][$k] = $c->id;

            return $c->id;
        }

        return 'new:'.$k;
    }

    /** @return array{lat:?float,lon:?float,quality:string,region:?string,commune:?string} */
    protected function geo(?string $address, ?string $name, string $seed): array
    {
        $g = CommuneLocator::locate($address, $name, $seed);

        return $g ? ['lat' => $g['lat'], 'lon' => $g['lon'], 'quality' => $g['quality'], 'region' => $g['region'], 'commune' => $g['commune']] : ['lat' => null, 'lon' => null, 'quality' => 'none', 'region' => null, 'commune' => CommuneLocator::communeOf($address)];
    }

    /** Sala existente por cadena + código (con memoria). */
    protected function findPoint(ImportContext $ctx, string $chainId, string $code): ?SelloutPoint
    {
        if (str_starts_with($chainId, 'new:')) {
            return null;
        }

        return SelloutPoint::where('organization_id', $this->org($ctx))->where('chain_id', $chainId)->whereRaw('lower(code) = ?', [mb_strtolower($code)])->first();
    }

    /** Mes (primer día) desde una fecha, «Enero 2025», «ene-25» o mes + año por separado. */
    public static function monthStart(?string $date, ?string $month = null, ?string $year = null, ?int $defaultYear = null): ?string
    {
        $date = trim((string) $date);
        if ($date !== '' && ($d = Cast::date($date))) {
            return substr($d, 0, 7).'-01';
        }
        $m = $y = null;
        $src = $date !== '' ? $date : trim((string) $month);
        if (preg_match('/^(\d{4})[-\/](\d{1,2})$/', $src, $x)) {
            [$y, $m] = [(int) $x[1], (int) $x[2]];
        } elseif ($src !== '') {
            $n = Cast::norm($src);
            foreach (self::MONTHS as $k => $v) {
                if (str_starts_with($n, $k)) {
                    $m = $v;
                    break;
                }
            }
            if (preg_match('/(\d{4}|\d{2})\s*$/', $n, $x)) {
                $y = (int) $x[1] < 100 ? 2000 + (int) $x[1] : (int) $x[1];
            }
        }
        $y ??= ($year !== null && $year !== '' ? (int) $year : $defaultYear);
        if (! $m || ! $y || $m < 1 || $m > 12 || $y < 2000 || $y > 2100) {
            return null;
        }

        return sprintf('%04d-%02d-01', $y, $m);
    }
}
