<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasUuids;

    protected $table = 'core.settings';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
