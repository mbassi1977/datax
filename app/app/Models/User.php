<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['tenant_id', 'person_id', 'organization_id', 'email', 'password', 'status', 'created_by', 'updated_by'])]
#[Hidden(['password', 'remember_token', 'avatar_path'])]
class User extends Authenticatable
{
    use Auditable, HasApiTokens, HasUuids, Notifiable, SoftDeletes;

    protected $table = 'security.users';

    protected $attributes = ['status' => 'active']; // igual que el default de la columna

    protected $appends = ['avatar_url'];

    protected function avatarUrl(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::get(fn () => app(\App\Support\Avatars\AvatarService::class)->userUrl($this));
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_superadmin' => 'boolean',
            'avatar_updated_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
