<?php

namespace App\Models\Asset;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetIssueMessage extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'asset_issue_id', 'author_id', 'message',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AssetIssue::class, 'asset_issue_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'author_id');
    }
}
