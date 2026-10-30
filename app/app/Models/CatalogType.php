<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CatalogType extends Model
{
    use HasUuids;

    protected $table = 'core.catalog_types';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['allow_tenant_items' => 'boolean'];
    }
}
