<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FieldAuditAnswer extends Model
{
    use HasUuids;

    protected $table = 'fieldaudit.answers';
    protected $guarded = ['id']; // tenant_id y estados los fija el controlador

    protected function casts(): array
    {
        return ['value' => 'array', 'client_updated_at' => 'datetime'];
    }
}
