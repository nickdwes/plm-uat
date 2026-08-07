<?php

namespace App\Models\Task;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A task, or a sub-task — they are the same thing at different depths.
 *
 * Not a project feature. Of the legacy's 9,055 tasks only a quarter carried a
 * project; the rest were support, training, meetings and plain internal work,
 * and its twelve categories doubled as the module map. So the project link is
 * one of several and all of them are optional.
 *
 * Sub-tasks are tasks. The legacy's separate sub-phase table meant a sub-phase
 * carried a name and nothing else — no owner, date or status — and one table
 * with a parent gives it everything a task has.
 */
class Task extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'tasks';

    protected $fillable = [
        'organization_id', 'parent_task_id', 'reference',
        'title', 'description', 'status', 'priority', 'deadline',
        'completed_at', 'position', 'created_by',

        // What kind of work, and what raised it. All optional — most tasks
        // belong to nothing but the person doing them.
        'category', 'project_id', 'opportunity_id', 'lead_id', 'research_id', 'account_id',

        // Where it came from, and what produced it. The pair is unique per
        // organisation, which is what stops a generator raising duplicates.
        'source', 'generated_from_type', 'generated_from_id',

        // Whether somebody else can cover it. Null follows the category.
        'handover_policy',

        'effort_estimate_days', 'started_on', 'assigned_to',

        // Who owes the work — us, the customer, or a vendor.
        'owner_party', 'owner_account_id', 'owner_vendor_partner_id',
        'owner_contact_id', 'owner_contact_name',

        // What actually happened, and who signs it off.
        'started_by', 'completed_by',
        'requires_approval', 'approver_id', 'approved_at', 'approved_by', 'approval_note',
    ];

    protected $casts = [
        'deadline'     => 'date',
        'started_on'   => 'date',
        'completed_at' => 'datetime',
        'approved_at'  => 'datetime',
        'position'     => 'integer',
        'requires_approval'    => 'boolean',
        'effort_estimate_days' => 'decimal:2',
    ];

    /**
     * Mirrors the column defaults, so a freshly created model reads the same
     * as one loaded back from the database rather than showing null until the
     * next fetch.
     */
    protected $attributes = [
        'status' => 'todo', 'priority' => 'medium',
        'owner_party' => 'internal', 'source' => 'manual',
    ];

    /** As the design's task list labels them. */
    public const STATUSES = ['todo', 'in_progress', 'awaiting_approval', 'completed', 'blocked'];

    public const PRIORITIES = ['low', 'medium', 'high'];

    /**
     * The legacy's twelve, which were the module map as much as a filter.
     *
     * Kept whole rather than trimmed to what is built: a task raised against a
     * ticket is a real thing people do, and dropping the category would just
     * move it into "internal" where nothing can find it.
     */
    public const CATEGORIES = [
        'opportunity', 'project', 'support', 'meeting', 'training', 'certificate',
        'research', 'collaboration', 'internal', 'ticket', 'account', 'leave',
    ];

    /** The one status that means the work is done, named once. */
    public const DONE = 'completed';

    /**
     * Who owes the work.
     *
     * 104 of the legacy's 9,055 tasks were owed by somebody else — a client or
     * a supplier — and 97 of those were tracked all the way to Completed. It is
     * a real state, not a note.
     */
    public const PARTIES = ['internal', 'customer', 'vendor'];

    /**
     * Where a task came from.
     *
     * The legacy kept this and never read it, so nobody could tell a task they
     * had been given from one they had written — and the first person to tidy
     * up deleted work the system silently raised again.
     */
    public const SOURCES = ['manual', 'recurrence', 'training', 'project', 'support', 'meeting'];

    /** Raised by the system rather than typed by somebody. */
    public function getIsGeneratedAttribute(): bool
    {
        return $this->source !== 'manual';
    }

    /**
     * Which kinds of work somebody else can pick up for a fortnight.
     *
     * The ones that can are customer-facing and time-critical — the cost of
     * waiting lands outside the company, so somebody has to hold them.
     *
     * The ones that cannot are not an oversight. Training and certificates are
     * yours and nobody can sit your exam. Research is a thread, and somebody
     * joining halfway hands back work that has to be redone. A meeting action
     * belongs to whoever was in the room. Project work belongs to the project
     * plan, and reallocating it is the project manager's call about resourcing
     * rather than something to settle in a leave form the day before a flight.
     *
     * A default rather than a rule — see `is_transferable`.
     */
    public const HANDOVER_BY_CATEGORY = [
        'support'       => true,
        'ticket'        => true,
        'account'       => true,
        'opportunity'   => true,
        'collaboration' => true,
        'internal'      => true,

        'project'     => false,
        'training'    => false,
        'certificate' => false,
        'meeting'     => false,
        'research'    => false,
        'leave'       => false,
    ];

    public const HANDOVER_POLICIES = ['transferable', 'personal'];

    /**
     * Can somebody else hold this while its owner is away?
     *
     * The task's own answer wins over the category's, because a self-raised
     * task might be personal admin or might be the one thing holding up a
     * delivery, and only the person going away knows which.
     */
    public function getIsTransferableAttribute(): bool
    {
        if ($this->handover_policy !== null) {
            return $this->handover_policy === 'transferable';
        }

        return self::HANDOVER_BY_CATEGORY[$this->category] ?? true;
    }

    /**
     * Why it cannot be covered, in words.
     *
     * Given rather than left implied: a rule people cannot see reads as the
     * system being obstructive, and they work around it by handing the task
     * over informally, which is exactly what this exists to stop.
     */
    public function getHandoverBlockReasonAttribute(): ?string
    {
        if ($this->is_transferable) {
            return null;
        }

        if ($this->handover_policy === 'personal') {
            return 'Marked as yours alone.';
        }

        return match ($this->category) {
            'training', 'certificate' => 'Training and certification are personal — nobody else can sit it.',
            'research'    => 'Research is a thread; handing it over halfway means it gets redone.',
            'meeting'     => 'The action belongs to whoever was in the room.',
            'project'     => 'Project work is reallocated by the project manager, not in a leave form.',
            'leave'       => 'Leave admin is personal.',
            default       => 'This kind of work is not handed over.',
        };
    }

    /** Cover that is still standing today. */
    public function activeHandover(): HasOne
    {
        return $this->hasOne(TaskHandover::class)->whereNull('released_at')->latest('id');
    }

    public function handovers(): HasMany
    {
        return $this->hasMany(TaskHandover::class);
    }

    /**
     * Who is holding it today — the coverer where one is standing, otherwise
     * whoever owns it. Ownership never moves, so it always comes back.
     */
    public function getHeldByIdAttribute(): ?int
    {
        return $this->activeHandover?->to_employee_id ?? $this->assigned_to;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project\Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'assigned_to');
    }

    public function ownerAccount(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Account\Account::class, 'owner_account_id');
    }

    public function ownerVendorPartner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Training\VendorPartner::class, 'owner_vendor_partner_id');
    }

    public function ownerContact(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Account\AccountContact::class, 'owner_contact_id');
    }

    /** Who has to sign it off. Named, so they can actually be told. */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'approver_id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'started_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'completed_by');
    }

    /** Requests to assist, delegate or collaborate. */
    public function requests(): HasMany
    {
        return $this->hasMany(TaskRequest::class);
    }

    /**
     * Documents this task cannot close without — the design's Resources tab.
     *
     * Not the same thing as its attachments, and the legacy kept them apart
     * too: 825 files on tasks, 386 named requirements. A requirement exists
     * before its file does, which is the whole reason it is a separate list.
     */
    public function requiredDocuments(): HasMany
    {
        return $this->hasMany(TaskRequiredDocument::class);
    }

    /** What is in the way. Not the same question as the task's own status. */
    public function issues(): HasMany
    {
        return $this->hasMany(TaskIssue::class)->latest('id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id')->orderBy('position');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TaskMember::class);
    }

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class, 'task_dependencies', 'task_id', 'depends_on_task_id',
        )->withTimestamps();
    }

    /**
     * Blockers that are not tasks here — "awaiting the client's firewall
     * change window" and the like. 172 of the legacy's 203 were this.
     *
     * Listed but not counted in `is_blocked`: nothing in the system can mark
     * one done, so treating it as a gate would leave tasks blocked forever on
     * a line of text.
     */
    public function externalDependencies(): HasMany
    {
        return $this->hasMany(TaskDependencyNote::class, 'task_id')
            ->whereNull('depends_on_task_id');
    }

    /** What is waiting on this one — the other direction of the same edge. */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class, 'task_dependencies', 'depends_on_task_id', 'task_id',
        )->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(\App\Models\Project\ProjectComment::class, 'task_id')
            ->whereNull('parent_id')->oldest('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(\App\Models\Project\ProjectAttachment::class, 'task_id');
    }

    // ── Derived ───────────────────────────────────────────────────────────

    public function getIsDoneAttribute(): bool
    {
        return $this->status === self::DONE;
    }

    /**
     * Whether anything it waits on is still unfinished.
     *
     * Surfaced rather than enforced. Refusing to let somebody start a task
     * because a dependency is open is the kind of rule that gets worked around
     * by deleting the dependency, which loses the information entirely.
     */
    public function getIsBlockedAttribute(): bool
    {
        return $this->relationLoaded('dependencies')
            ? $this->dependencies->contains(fn (self $t) => ! $t->is_done)
            : $this->dependencies()->where('status', '!=', self::DONE)->exists();
    }

    /**
     * Ours to do, rather than ours to chase.
     *
     * The distinction the legacy's `task_owner` was reaching for and could not
     * hold, because it was a string that 974 rows left blank. An externally
     * owned task still sits in somebody's list here — somebody has to chase it
     * — but the hours are not ours and counting them as our capacity makes our
     * own delivery look worse than it is.
     */
    public function getIsOursAttribute(): bool
    {
        return $this->owner_party === 'internal';
    }

    /** Who owes it, in words — for a badge that has to read at a glance. */
    public function getOwnerNameAttribute(): ?string
    {
        return match ($this->owner_party) {
            'customer' => $this->ownerAccount?->name,
            'vendor'   => $this->ownerVendorPartner?->name,
            default    => null,
        };
    }

    /**
     * Who at that organisation.
     *
     * The contact record where they are on file, the typed name otherwise —
     * which was 95 of the legacy's 104, because a client's engineer is rarely
     * on file before the task naming them exists.
     */
    public function getOwnerContactNameResolvedAttribute(): ?string
    {
        if ($this->owner_contact_id && $this->ownerContact) {
            return trim($this->ownerContact->first_name . ' ' . $this->ownerContact->last_name);
        }

        return $this->owner_contact_name;
    }

    /** Externally owned and not finished — what somebody has to chase. */
    public function scopeAwaitingOthers(Builder $q): Builder
    {
        return $q->where('owner_party', '!=', 'internal')->where('status', '!=', self::DONE);
    }

    public function getIsOverdueAttribute(): bool
    {
        return ! $this->is_done
            && $this->deadline !== null
            && $this->deadline->isPast();
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    /** Top-level only — sub-tasks belong under their parent, not beside it. */
    public function scopeRoots(Builder $q): Builder
    {
        return $q->whereNull('parent_task_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', '!=', self::DONE);
    }

    /**
     * How long it actually took, in days.
     *
     * Against `effort_estimate_days`, this is the comparison the legacy had the
     * data for on nine tasks in ten and never once made.
     */
    public function getElapsedDaysAttribute(): ?int
    {
        if ($this->started_on === null || $this->completed_at === null) {
            return null;
        }

        return (int) $this->started_on->diffInDays($this->completed_at->startOfDay());
    }

    /** Days over or under the estimate. Null until both exist. */
    public function getEffortVarianceAttribute(): ?float
    {
        return $this->effort_estimate_days === null || $this->elapsed_days === null
            ? null
            : round($this->elapsed_days - (float) $this->effort_estimate_days, 2);
    }
}
