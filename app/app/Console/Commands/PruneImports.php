<?php

namespace App\Console\Commands;

use App\Models\ImportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Borra archivos de importaciones subidas y nunca procesadas (contienen datos personales). */
class PruneImports extends Command
{
    protected $signature = 'imports:prune {--hours=24}';
    protected $description = 'Elimina archivos de importaciones abandonadas';

    public function handle(): int
    {
        $jobs = ImportJob::whereNotNull('stored_path')->whereIn('status', ['uploaded', 'simulated', 'failed'])
            ->where('created_at', '<', now()->subHours((int) $this->option('hours')))->get();

        foreach ($jobs as $job) {
            Storage::disk('local')->delete($job->stored_path);
            $job->update(['stored_path' => null, 'status' => 'failed', 'errors' => [['row' => null, 'field' => null, 'message' => 'Archivo eliminado por inactividad.']]]);
        }
        $this->info("Archivos eliminados: {$jobs->count()}");

        return self::SUCCESS;
    }
}
