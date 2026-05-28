<?php

namespace App\Models\Preboarding;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class PreboardingAttachment extends Model
{
    use SoftDeletes;

    protected $table = 'preboarding_attachments';

    protected $fillable = [
        'preboarding_id', 'category',
        'original_name', 'storage_path',
        'mime_type', 'size_bytes', 'uploaded_by',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(PreboardingRecord::class, 'preboarding_id');
    }

    public function fileExists(): bool
    {
        return Storage::disk('preboarding_docs')->exists($this->storage_path);
    }

    public function readStream()
    {
        return Storage::disk('preboarding_docs')->readStream($this->storage_path);
    }
}
