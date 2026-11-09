<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SelloutChain extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'sellout.chains';
    protected $guarded = ['id']; // tenant_id y organization_id los fija el controlador
    protected $hidden = ['logo_path'];
}
