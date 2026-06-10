<?php

namespace App\Models\Recruitment;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterviewPanelist extends Model
{
    protected $table = 'interview_panelists';

    protected $fillable = [
        'interview_id',
        'employee_id',
        'external_email',
        'external_name',
        'role',
        'invite_sent',
        'invite_sent_at',
        'confirmed',
        'scoring_token',
        'scoring_token_expires_at',
    ];

    protected $casts = [
        'invite_sent'               => 'boolean',
        'confirmed'                 => 'boolean',
        'invite_sent_at'            => 'datetime',
        'scoring_token_expires_at'  => 'datetime',
    ];

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class, 'interview_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
