<?php

namespace App\Models\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class RcaEvidenceFile extends Model
{
    use SoftDeletes;

    protected $table = 'rca_evidence_files';

    protected $fillable = [
        'ticket_rca_id', 'original_name', 'storage_path', 'mime_type', 'size_bytes', 'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function rca(): BelongsTo
    {
        return $this->belongsTo(TicketRca::class, 'ticket_rca_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function fileExists(): bool
    {
        return Storage::disk('rca_evidence')->exists($this->storage_path);
    }

    /*
     * No readStream() here, same reason as TrainingEvidenceFile — what's on
     * disk is ciphertext. Everything serving this file goes through
     * DocumentVault::download(), which decrypts.
     */
}
