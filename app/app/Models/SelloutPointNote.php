<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;


class SelloutPointNote extends Model
{
    use HasUuids;

    protected $table = 'sellout.point_notes';
    protected $guarded = ['id']; // tenant_id y organization_id los fija el controlador
}
