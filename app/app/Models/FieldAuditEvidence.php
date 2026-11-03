<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FieldAuditEvidence extends Model
{
    use HasUuids;

    protected $table = 'fieldaudit.evidences';
    protected $guarded = ['id']; // tenant_id y estados los fija el controlador
    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float'];
    }
}
