<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use Auditable, HasUuids, SoftDeletes;

    protected $table = 'master.projects';
    protected $guarded = ['id']; // tenant_id lo fija el controlador, nunca viene del request
}
