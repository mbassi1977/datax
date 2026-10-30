<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Avatar extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'core.avatars';
    protected $guarded = ['id'];
    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(AvatarCollection::class, 'collection_id');
    }
}
