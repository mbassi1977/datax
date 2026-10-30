<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    use HasUuids;

    protected $table = 'security.profiles';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }
}
