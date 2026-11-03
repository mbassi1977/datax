<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class FieldAudit extends Model
{
    use Auditable, HasUuids, SoftDeletes;

    protected $table = 'fieldaudit.audits';
    protected $guarded = ['id']; // tenant_id y estados los fija el controlador

    protected function casts(): array
    {
        return ['structure' => 'array', 'started_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'score' => 'float', 'scheduled_for' => 'date:Y-m-d', 'due_on' => 'date:Y-m-d'];
    }
}
