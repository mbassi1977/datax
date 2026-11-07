<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SelloutProduct extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'sellout.products';
    protected $guarded = ['id']; // tenant_id y organization_id los fija el controlador
    protected $hidden = ['image_path'];
    protected function casts(): array
    {
        return ['aliases'=>'array','active'=>'boolean'];
    }
}
