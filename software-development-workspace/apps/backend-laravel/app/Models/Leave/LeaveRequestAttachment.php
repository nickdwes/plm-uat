<?php

namespace App\Models\Leave;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Storage\DocumentVault;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A document supporting a leave request — a sick note, a death notice.
 *
 * No organization_id and so no global scope: the column would be a second
 * copy of a fact the parent request already holds, and two copies of a tenancy
 * key is how a row ends up readable in the wrong organisation. Reach these
 * through the request, which is scoped.
 */
class LeaveRequestAttachment extends Model
{
    use SoftDeletes;

    /** The disk these share with personnel documents — encrypted, private. */
    public const DISK = 'employee_documents';

    /** Kept apart from the personnel files so a purge of one cannot take the other. */
    public const DIRECTORY = 'leave-attachments';

    protected $fillable = [
        'leave_request_id', 'uploaded_by',
        'original_name', 'storage_path', 'mime_type', 'size_bytes',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Whether the bytes are still there.
     *
     * A row whose file has gone is worse than no row: the screen offers a
     * download that 500s. Checked before every download so it 404s honestly.
     */
    public function fileExists(): bool
    {
        return Storage::disk(self::DISK)->exists($this->storage_path);
    }

    /** Remove the bytes as well as the row, so a delete is not half a delete. */
    public function purge(): void
    {
        DocumentVault::delete(self::DISK, $this->storage_path);

        $this->delete();
    }
}
