<?php

namespace App\Models\Security;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityStepUpCode extends Model
{
    protected $fillable = [
        'user_id', 'purpose', 'code_hash', 'expires_at', 'attempts',
        'verified_at', 'elevated_until', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'expires_at'     => 'datetime',
        'verified_at'    => 'datetime',
        'elevated_until' => 'datetime',
        'attempts'       => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
