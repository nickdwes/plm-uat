<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingDocumentChecklist extends Model
{
    protected $table = 'onboarding_document_checklist';

    protected $fillable = ['employee_id', 'label', 'done', 'notes', 'sort_order'];

    protected $casts = ['done' => 'boolean'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
