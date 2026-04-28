<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')->withTimestamps();
    }

    public function waAccounts(): HasMany
    {
        return $this->hasMany(WaAccount::class);
    }

    public function waSessions(): HasMany
    {
        return $this->hasMany(WaSession::class);
    }
}
