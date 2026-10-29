<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    use HasUuids;

    protected $table = 'integrations.import_jobs';
    protected $guarded = ['id'];
    protected $hidden = ['stored_path'];

    protected function casts(): array
    {
        return ['errors' => 'array', 'warnings' => 'array', 'mapping' => 'array', 'options' => 'array', 'finished_at' => 'datetime'];
    }
}
