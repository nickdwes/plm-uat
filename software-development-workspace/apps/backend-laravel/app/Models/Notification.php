<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Traits\HasUuids;

class Notification extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'user_id',
        'type',
        'title',
        'message',
        'data',
        'notifiable_type',
        'notifiable_id',
        'read_at',
        'sent_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: Unread notifications
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope: By organization
     */
    public function scopeByOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Mark as read
     */
    public function markAsRead()
    {
        $this->update(['read_at' => now()]);
    }

    /**
     * Mark as unread
     */
    public function markAsUnread()
    {
        $this->update(['read_at' => null]);
    }
}
