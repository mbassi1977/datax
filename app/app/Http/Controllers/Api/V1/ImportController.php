<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Imports\Cast;
use App\Imports\ImportContext;
use App\Imports\ImportRegistry;
use App\Imports\ImportRunner;
use App\Imports\ImportTarget;
use App\Imports\SpreadsheetReader;
use App\Models\ImportJob;
use App\Models\ImportTemplate;
use App\Support\Access\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Importador universal: carga de archivo, mapeo de columnas, simulación y carga. */
class ImportController extends Controller
{
    public function __construct(private AccessService $access) {}

    /** Destinos que el usuario puede cargar, con sus campos. */
    public function targets(Request $request): JsonResponse
    {
        $out = [];
        foreach (ImportRegistry::all() as $t) {
            if ($this->access->can($request->user(), $t->permission().'.crear', $request->header('X-Profile'))) {
                $out[] = ['key' => $t->key(), 'label' => $t->label(), 'fields' => collect($t->fields())->map(fn ($f, $k) => ['key' => $k, 'label' => $f['label'], 'required' => $f['required'] ?? false])->values()];
            }
        }

        return response()->json($out);
    }

    /** Plantilla CSV (separador ";" y UTF-8 con BOM: abre bien en Excel en español). */
    public function template(Request $request, string $target): StreamedResponse
    {
        $t = $this->target($request, $target);
        $fields = $t->fields();

        return response()->streamDownload(function () use ($fields) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_column($fields, 'label'), ';');
            fputcsv($out, array_column($fields, 'example'), ';');
            fclose($out);
        }, "plantilla-{$t->key()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Paso 1: sube el archivo; devuelve columnas, vista previa y mapeo sugerido. */
    public function upload(Request $request): JsonResponse
    {
        $request->validate(['target' => ['required', 'string'], 'file' => ['required', 'file', 'max:5120'], 'template_id' => ['nullable', 'uuid']]);
        $t = $this->target($request, $request->input('target'));
        $file = $request->file('file');

        try {
            $ext = SpreadsheetReader::extension($file->getClientOriginalName());
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        $tenant = $request->user()->tenant_id;
        $job = ImportJob::create(['tenant_id' => $tenant, 'user_id' => $request->user()->id, 'target' => $t->key(), 'original_name' => $file->getClientOriginalName()]);
        $stored = $file->storeAs("imports/{$tenant}", "{$job->id}.{$ext}", 'local');
        $job->update(['stored_path' => $stored]);

        $headers = [];
        $preview = [];
        $total = 0;
        try {
            foreach (SpreadsheetReader::rows(Storage::disk('local')->path($stored), $ext) as $cells) {
                if (array_filter($cells, fn ($c) => $c !== '') === []) {
                    continue;
                }
                if ($headers === []) {
                    $headers = $cells;
                    continue;
                }
                $total++;
                if (count($preview) < 5) {
                    $preview[] = $cells;
                }
                if ($total > SpreadsheetReader::MAX_ROWS) {
                    abort(422, 'El archivo supera el máximo de '.SpreadsheetReader::MAX_ROWS.' filas por importación. Divídalo en partes.');
                }
            }
        } catch (RuntimeException $e) {
            abort(422, 'No se pudo leer el archivo: '.$e->getMessage());
        }
        if ($headers === []) {
            abort(422, 'El archivo está vacío.');
        }
        $job->update(['total_rows' => $total, 'options' => ['headers' => $headers]]);

        $template = $request->filled('template_id') ? ImportTemplate::where('tenant_id', $tenant)->where('target', $t->key())->find($request->input('template_id')) : null;

        return response()->json([
            'id' => $job->id, 'headers' => $headers, 'preview' => $preview, 'total_rows' => $total,
            'suggested_mapping' => $template ? $this->fromTemplate($t, $headers, $template->mapping) : $this->suggest($t, $headers),
            'templates' => ImportTemplate::where('tenant_id', $tenant)->where('target', $t->key())->orderBy('name')->get(['id', 'name']),
        ], 201);
    }

    /** Paso 2: simula (mode=simulate) o carga (mode=commit) con el mapeo indicado. */
    public function run(Request $request, string $id, ImportRunner $runner): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:simulate,commit'],
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'integer', 'min:0'],
            'update_existing' => ['boolean'],
            'on_error' => ['in:skip,abort'],
            'organization_id' => ['nullable', 'uuid'],
            'save_template_as' => ['nullable', 'string', 'max:100'],
        ]);

        $job = ImportJob::where('tenant_id', $request->user()->tenant_id)->where('user_id', $request->user()->id)->findOrFail($id);
        abort_if(in_array($job->status, ['completed', 'rejected'], true), 409, 'Esta importación ya fue procesada. Suba el archivo nuevamente.');
        $t = $this->target($request, $job->target);

        $mapping = [];
        foreach ($t->fields() as $key => $def) {
            $mapping[$key] = isset($data['mapping'][$key]) ? (int) $data['mapping'][$key] : null;
            if (($def['required'] ?? false) && $mapping[$key] === null) {
                abort(422, "Debe indicar qué columna corresponde a «{$def['label']}».");
            }
        }

        $options = ['update_existing' => (bool) ($data['update_existing'] ?? false), 'on_error' => $data['on_error'] ?? 'skip', 'organization_id' => $data['organization_id'] ?? null];
        if ($options['organization_id'] && ! $this->access->canOnOrganization($request->user(), $t->permission().'.crear', $options['organization_id'], $request->header('X-Profile'))) {
            abort(403, 'La organización indicada está fuera de su alcance.');
        }
        if ($options['organization_id'] && ! DB::table('core.organizations')->where('tenant_id', $request->user()->tenant_id)->where('id', $options['organization_id'])->exists()) {
            abort(422, 'La organización indicada no existe.');
        }

        $headers = $job->options['headers'] ?? [];
        $ctx = new ImportContext($request->user(), $this->access, $options, $request->header('X-Profile'));
        try {
            $job = $runner->run($job, $t, $mapping, $data['mode'] === 'commit', $options, $ctx);
        } catch (RuntimeException $e) {
            $job->update(['status' => 'failed', 'errors' => [['row' => null, 'field' => null, 'message' => $e->getMessage()]]]);
            abort(422, $e->getMessage());
        }

        if ($data['mode'] === 'commit' && $job->status === 'completed' && ! empty($data['save_template_as'])) {
            ImportTemplate::updateOrCreate(
                ['tenant_id' => $job->tenant_id, 'target' => $t->key(), 'name' => $data['save_template_as']],
                ['mapping' => $this->namesFor($mapping, $headers), 'options' => $options, 'created_by' => $request->user()->id],
            );
        }

        return response()->json($job);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(ImportJob::where('tenant_id', $request->user()->tenant_id)->where('status', '!=', 'uploaded')->latest()->paginate(20, ['id', 'target', 'original_name', 'status', 'total_rows', 'created_count', 'updated_count', 'skipped_count', 'error_count', 'created_at', 'finished_at', 'user_id']));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(ImportJob::where('tenant_id', $request->user()->tenant_id)->findOrFail($id));
    }

    public function templates(Request $request): JsonResponse
    {
        return response()->json(ImportTemplate::where('tenant_id', $request->user()->tenant_id)->orderBy('target')->orderBy('name')->get());
    }

    public function destroyTemplate(Request $request, string $id): JsonResponse
    {
        ImportTemplate::where('tenant_id', $request->user()->tenant_id)->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    private function target(Request $request, string $key): ImportTarget
    {
        $t = ImportRegistry::get($key);
        abort_unless($t, 422, 'Destino de importación desconocido.');
        abort_unless($this->access->can($request->user(), $t->permission().'.crear', $request->header('X-Profile')), 403, 'Sin permiso para importar '.$t->label().'.');

        return $t;
    }

    /** Sugiere columna por campo: coincidencia exacta con nombre/etiqueta/sinónimos, luego por contenido. */
    private function suggest(ImportTarget $t, array $headers): array
    {
        $norm = array_map(fn ($h) => Cast::norm($h), $headers);
        $used = [];
        $out = [];
        foreach ($t->fields() as $key => $def) {
            $names = array_map([Cast::class, 'norm'], array_merge([$key, $def['label']], $def['synonyms'] ?? []));
            $found = null;
            foreach ($norm as $i => $h) {
                if (! isset($used[$i]) && $h !== '' && in_array($h, $names, true)) { $found = $i; break; }
            }
            if ($found === null) {
                foreach ($norm as $i => $h) {
                    if (isset($used[$i]) || $h === '') { continue; }
                    foreach ($names as $n) {
                        if (strlen($n) >= 4 && (str_contains($h, $n) || str_contains($n, $h) && strlen($h) >= 4)) { $found = $i; break 2; }
                    }
                }
            }
            $found !== null && $used[$found] = true;
            $out[$key] = $found;
        }

        return $out;
    }

    private function fromTemplate(ImportTarget $t, array $headers, array $saved): array
    {
        $norm = array_map(fn ($h) => Cast::norm($h), $headers);
        $suggest = $this->suggest($t, $headers);
        foreach ($t->fields() as $key => $_) {
            if (isset($saved[$key])) {
                $i = array_search(Cast::norm($saved[$key]), $norm, true);
                $suggest[$key] = $i === false ? $suggest[$key] : $i;
            }
        }

        return $suggest;
    }

    private function namesFor(array $mapping, array $headers): array
    {
        return array_filter(array_map(fn ($i) => $i !== null ? ($headers[$i] ?? null) : null, $mapping));
    }
}
