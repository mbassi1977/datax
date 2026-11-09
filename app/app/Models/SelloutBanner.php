<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SelloutBanner extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'sellout.banners';
    protected $guarded = ['id'];
    protected $hidden = ['logo_path'];

    protected function casts(): array
    {
        return ['aliases' => 'array'];
    }
}
