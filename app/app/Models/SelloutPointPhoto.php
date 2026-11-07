<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;


class SelloutPointPhoto extends Model
{
    use HasUuids;

    protected $table = 'sellout.point_photos';
    protected $guarded = ['id']; // tenant_id y organization_id los fija el controlador
    protected $hidden = ['path'];
}
