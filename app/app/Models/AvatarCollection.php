<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AvatarCollection extends Model
{
    use Auditable, HasUuids, SoftDeletes;

    protected $table = 'core.avatar_collections';
    protected $guarded = ['id']; // tenant_id lo fija el controlador, no viene de la validación

    protected function casts(): array
    {
        return ['is_global' => 'boolean', 'is_active' => 'boolean'];
    }

    public function avatars(): HasMany
    {
        return $this->hasMany(Avatar::class, 'collection_id')->orderBy('sort')->orderBy('name');
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'core.avatar_collection_organization', 'collection_id', 'organization_id');
    }
}
