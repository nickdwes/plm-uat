<?php

namespace App\Models\Training;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class TrainingEvidenceFile extends Model
{
    use SoftDeletes;

    protected $table = 'training_evidence_files';

    protected $fillable = [
        'training_record_id', 'employee_id', 'category',
        'original_name', 'storage_path', 'mime_type', 'size_bytes', 'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function trainingRecord(): BelongsTo
    {
        return $this->belongsTo(EmployeeTrainingRecord::class, 'training_record_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ── File helpers ───────────────────────────────────────────────────────────

    public function fileExists(): bool
    {
        return Storage::disk('training_evidence')->exists($this->storage_path);
    }

    public function readStream(): mixed
    {
        return Storage::disk('training_evidence')->readStream($this->storage_path);
    }
}
