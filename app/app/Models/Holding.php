<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Holding extends Model
{
    use Auditable, HasUuids, SoftDeletes;

    protected $table = 'core.holdings';
    protected $guarded = ['id']; // tenant_id lo fija el controlador

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'holding_id');
    }
}
