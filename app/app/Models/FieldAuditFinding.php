<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class FieldAuditFinding extends Model
{
    use Auditable, HasUuids, SoftDeletes;

    protected $table = 'fieldaudit.findings';
    protected $guarded = ['id']; // tenant_id y estados los fija el controlador

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'due_on' => 'date:Y-m-d'];
    }
}
