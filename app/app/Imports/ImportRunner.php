<?php

namespace App\Imports;

use App\Models\AuditEvent;
use App\Models\ImportJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Flujo: archivo → mapeo → validación → (simulación | carga) → auditoría (cap. 15.7). */
class ImportRunner
{
    private const MAX_ERRORS = 500;

    /**
     * @param array<string, int|null> $mapping campo HRX => índice de columna
     * @param array{update_existing?:bool, on_error?:string, organization_id?:?string} $options
     */
    public function run(ImportJob $job, ImportTarget $target, array $mapping, bool $commit, array $options, ImportContext $ctx): ImportJob
    {
        $onError = $options['on_error'] ?? 'skip';

        // "abort": nada se escribe si hay algún error. Se valida primero en seco.
        if ($commit && $onError === 'abort') {
            $dry = $this->process($job, $target, $mapping, false, $options, $ctx);
            if ($dry['error_rows'] > 0) {
                return $this->finish($job, 'rejected', $dry, $mapping, $options);
            }
        }

        $stats = DB::transaction(fn () => $this->process($job, $target, $mapping, $commit, $options, $ctx));
        $job = $this->finish($job, $commit ? 'completed' : 'simulated', $stats, $mapping, $options);

        if ($commit) {
            AuditEvent::record('import_completed', null, null, [
                'target' => $target->key(), 'file' => $job->original_name, 'job_id' => $job->id,
                'created' => $job->created_count, 'updated' => $job->updated_count, 'skipped' => $job->skipped_count, 'errors' => $job->error_count,
            ]);
            if ($job->stored_path) {
                Storage::disk('local')->delete($job->stored_path); // no se conservan datos personales en disco
                $job->update(['stored_path' => null]);
            }
        }

        return $job;
    }

    private function process(ImportJob $job, ImportTarget $target, array $mapping, bool $commit, array $options, ImportContext $ctx): array
    {
        $path = Storage::disk('local')->path((string) $job->stored_path);
        if (! is_file($path)) {
            throw new RuntimeException('El archivo ya no está disponible. Súbalo nuevamente.');
        }

        $stats = ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'error_rows' => 0, 'errors' => []];
        $seen = [];
        $headerSeen = false;
        $update = (bool) ($options['update_existing'] ?? false);

        foreach (SpreadsheetReader::rows($path, SpreadsheetReader::extension($job->original_name)) as $n => $cells) {
            if (! $headerSeen) {
                if (array_filter($cells, fn ($c) => $c !== '') !== []) {
                    $headerSeen = true; // primera fila con contenido = encabezado
                }
                continue;
            }
            if (array_filter($cells, fn ($c) => $c !== '') === []) {
                continue; // fila vacía
            }
            if (++$stats['total'] > SpreadsheetReader::MAX_ROWS) {
                throw new RuntimeException('El archivo supera el máximo de '.SpreadsheetReader::MAX_ROWS.' filas por importación. Divídalo en partes.');
            }

            $raw = [];
            foreach ($mapping as $field => $index) {
                if ($index !== null) {
                    $raw[$field] = $cells[$index] ?? '';
                }
            }

            [$data, $errors] = $target->normalize($raw, $ctx);
            $message = null;
            $existing = null;

            if ($errors === []) {
                $key = $target->dedupeKey($data);
                if ($key !== null && isset($seen[$key])) {
                    $errors['*'] = "Repetido en el archivo (ya aparece en la fila {$seen[$key]}).";
                } else {
                    $key !== null && $seen[$key] = $n;
                    $existing = $target->findExisting($ctx, $data);
                    if ($existing && ! $update) {
                        $stats['skipped']++;
                        continue;
                    }
                    $message = $existing ? $target->denyUpdate($ctx, $existing, $data) : $target->denyCreate($ctx, $data);
                    $message && $errors['*'] = $message;
                }
            }

            if ($errors !== []) {
                $stats['error_rows']++;
                foreach ($errors as $field => $msg) {
                    if (count($stats['errors']) < self::MAX_ERRORS) {
                        $stats['errors'][] = ['row' => $n, 'field' => $field === '*' ? null : $field, 'message' => $msg];
                    }
                }
                continue;
            }

            if ($existing) {
                $commit && $target->update($ctx, $existing, $data);
                $stats['updated']++;
            } else {
                $commit && $target->create($ctx, $data);
                $stats['created']++;
            }
        }

        return $stats;
    }

    private function finish(ImportJob $job, string $status, array $s, array $mapping, array $options): ImportJob
    {
        $job->update([
            'status' => $status, 'total_rows' => $s['total'], 'created_count' => $s['created'], 'updated_count' => $s['updated'],
            'skipped_count' => $s['skipped'], 'error_count' => $s['error_rows'], 'errors' => $s['errors'],
            'mapping' => $mapping, 'options' => $options, 'finished_at' => in_array($status, ['completed', 'rejected'], true) ? now() : null,
        ]);

        return $job;
    }
}
