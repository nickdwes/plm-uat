<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class EmployeeDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'uploaded_by',
        'category',
        'original_name',
        'storage_path',
        'mime_type',
        'size_bytes',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Check whether the underlying file still exists on the private disk.
     */
    public function fileExists(): bool
    {
        return Storage::disk('employee_documents')->exists($this->storage_path);
    }

    /**
     * Stream the file contents from the private disk.
     */
    public function readStream(): mixed
    {
        return Storage::disk('employee_documents')->readStream($this->storage_path);
    }
}
