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

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function leadProfiles(): HasMany
    {
        return $this->hasMany(LeadProfile::class);
    }

    public function leadScores(): HasMany
    {
        return $this->hasMany(LeadScore::class);
    }

    public function leadSources(): HasMany
    {
        return $this->hasMany(LeadSource::class);
    }
}
