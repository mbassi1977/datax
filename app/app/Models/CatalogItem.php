<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CatalogItem extends Model
{
    use Auditable, HasUuids, SoftDeletes;

    protected $table = 'core.catalogs';
    protected $guarded = ['id']; // tenant_id lo fija el controlador

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort' => 'integer'];
    }
}
