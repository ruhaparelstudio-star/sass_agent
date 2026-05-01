<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAiSetting extends Model
{
    protected $fillable = [
        'tenant_id',
        'ai_tone',
        'reply_delay_seconds',
        'followup_enabled',
        'followup_delay_hours',
        'pricelist_mode',
        'pricelist_min_requirement',
        'pricelist_file_enabled',
        'out_of_hours_auto_reply',
        'out_of_hours_message',
    ];

    protected function casts(): array
    {
        return [
            'followup_enabled' => 'bool',
            'pricelist_file_enabled' => 'bool',
            'out_of_hours_auto_reply' => 'bool',
            'reply_delay_seconds' => 'int',
            'followup_delay_hours' => 'int',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
