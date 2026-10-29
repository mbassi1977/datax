<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CostCenter extends Model
{
    use Auditable, HasUuids, SoftDeletes;

    protected $table = 'core.cost_centers';
    protected $guarded = ['id']; // tenant_id lo fija el controlador, nunca viene del request
}
