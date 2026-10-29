<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ImportTemplate extends Model
{
    use HasUuids;

    protected $table = 'integrations.import_templates';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mapping' => 'array', 'options' => 'array'];
    }
}
