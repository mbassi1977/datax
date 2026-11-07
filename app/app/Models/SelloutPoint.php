<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SelloutPoint extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'sellout.points';
    protected $guarded = ['id']; // tenant_id y organization_id los fija el controlador
    protected function casts(): array
    {
        return ['latitude'=>'float','longitude'=>'float'];
    }
}
