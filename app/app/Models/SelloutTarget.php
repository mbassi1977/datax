<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SelloutTarget extends Model
{
    use HasUuids;

    protected $table = 'sellout.targets';
    protected $guarded = ['id'];
}
