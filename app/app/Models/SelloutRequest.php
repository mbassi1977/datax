<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;


class SelloutRequest extends Model
{
    use HasUuids;

    protected $table = 'sellout.requests';
    protected $guarded = ['id']; // tenant_id y organization_id los fija el controlador
    protected $hidden = ['response_photo'];
    protected function casts(): array
    {
        return ['log'=>'array','responded_at'=>'datetime','closed_at'=>'datetime'];
    }
}
