<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use Auditable, HasUuids, SoftDeletes;

    public const TYPES = ['company', 'client', 'provider', 'otec', 'evaluator', 'public', 'social'];

    protected $table = 'core.organizations';
    protected $guarded = ['id']; // tenant_id nunca viene de la validación: lo fija el controlador
}
