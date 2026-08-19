<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Support\AssignmentRule;
use App\Models\Support\CannedResponse;
use App\Models\Support\SavedView;
use App\Models\Support\Team;
use App\Models\Support\TeamMember;
use App\Models\Support\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teams, routing rules, canned answers and saved queues.
 *
 * The machinery that turns a table of tickets into somewhere to work. The old
 * module had none of it — assignment was delegated to the tasks module, so a
 * ticket was unowned until somebody made a task out of it, and everything sat
 * in one shared pile.
 */
class WorkspaceController extends Controller
{
    /** Everything the workspace screens need, in one call. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Ticket::class);

        return response()->json([
            'teams' => Team::with('members.user:id,name')->orderBy('name')->get()
                ->map(fn (Team $t) => [
                    'id'   => $t->id,
                    'name' => $t->name,
                    'description' => $t->description,
                    'is_default'  => $t->is_default,
                    'members'     => $t->members->map(fn (TeamMember $m) => [
                        'id'      => $m->id,
                        'user_id' => $m->user_id,
                        'name'    => $m->user?->name,
                        'is_lead' => $m->is_lead,
                        'accepts_assignment' => $m->accepts_assignment,
                    ]),
                ]),

            'rules' => AssignmentRule::with('team:id,name')->orderBy('position')->orderBy('id')->get(),

            /*
             * Team responses first, then everybody's, most-used at the top. A
             * canned response nobody picks reads badly, and this ordering lets
             * the good ones rise without anybody curating the list.
             */
            'canned' => CannedResponse::orderByDesc('use_count')->orderBy('title')
                ->get(['id', 'title', 'body', 'support_team_id', 'use_count']),

            'views' => SavedView::where(fn ($q) => $q->whereNull('user_id')
                    ->orWhere('user_id', $request->user()?->id))
                ->orderBy('position')->orderBy('name')->get(),
        ]);
    }

    // ── Teams ─────────────────────────────────────────────────────────────────

    public function storeTeam(Request $request): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_default'  => ['boolean'],
        ]);

        $team = Team::create($data + [
            'organization_id' => app('current.organization_id'),
            'slug'            => \Str::slug($data['name']),
        ]);

        return response()->json(['data' => $team], 201);
    }

    public function saveMember(Request $request, int $team): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $data = $request->validate([
            'user_id'            => ['required', 'integer', 'exists:users,id'],
            'is_lead'            => ['boolean'],
            'accepts_assignment' => ['boolean'],
        ]);

        $record = Team::findOrFail($team);

        $member = TeamMember::updateOrCreate(
            ['support_team_id' => $record->id, 'user_id' => $data['user_id']],
            [
                'is_lead' => $data['is_lead'] ?? false,
                // Somebody on leave stays on the team and stops receiving work.
                'accepts_assignment' => $data['accepts_assignment'] ?? true,
            ],
        );

        return response()->json(['data' => $member->load('user:id,name')], 201);
    }

    public function removeMember(int $team, int $member): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        Team::findOrFail($team);
        TeamMember::where('support_team_id', $team)->findOrFail($member)->delete();

        return response()->json(['deleted' => true]);
    }

    // ── Routing ───────────────────────────────────────────────────────────────

    public function saveRule(Request $request): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        $data = $request->validate([
            'id'                => ['nullable', 'integer', 'exists:support_assignment_rules,id'],
            'name'              => ['required', 'string', 'max:160'],
            'position'          => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active'         => ['boolean'],
            'match_account_id'  => ['nullable', 'integer', 'exists:accounts,id'],
            'match_type_id'     => ['nullable', 'integer', 'exists:support_ticket_types,id'],
            'match_priority_id' => ['nullable', 'integer', 'exists:support_ticket_priorities,id'],
            'match_domain'      => ['nullable', 'string', 'max:200'],
            'match_keyword'     => ['nullable', 'string', 'max:200'],
            'assign_team_id'    => ['nullable', 'integer', 'exists:support_teams,id'],
            'assign_mode'       => ['required', 'in:none,round_robin,least_busy,specific'],
            'assign_user_id'    => ['nullable', 'integer', 'exists:users,id'],
        ]);

        // Picking a person by name and then a mode that ignores them is the
        // most common way to build a rule that appears to do nothing.
        if ($data['assign_mode'] === AssignmentRule::MODE_SPECIFIC && empty($data['assign_user_id'])) {
            return response()->json([
                'message' => 'Assigning to a specific person needs a person. Choose one, or pick '
                    . 'round-robin or least-busy to share it out across the team.',
            ], 422);
        }

        if (in_array($data['assign_mode'], [AssignmentRule::MODE_ROUND_ROBIN, AssignmentRule::MODE_LEAST_BUSY], true)
            && empty($data['assign_team_id'])) {
            return response()->json([
                'message' => 'Sharing work out needs a team to share it across.',
            ], 422);
        }

        $rule = AssignmentRule::updateOrCreate(
            ['id' => $data['id'] ?? null],
            collect($data)->except('id')->all() + ['organization_id' => app('current.organization_id')],
        );

        return response()->json(['data' => $rule->load('team:id,name')], 201);
    }

    public function destroyRule(int $rule): JsonResponse
    {
        $this->authorize('manageConfig', Ticket::class);

        AssignmentRule::findOrFail($rule)->delete();

        return response()->json(['deleted' => true]);
    }

    // ── Canned responses ──────────────────────────────────────────────────────

    public function saveCanned(Request $request): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $data = $request->validate([
            'id'              => ['nullable', 'integer', 'exists:support_canned_responses,id'],
            'title'           => ['required', 'string', 'max:160'],
            'body'            => ['required', 'string'],
            'support_team_id' => ['nullable', 'integer', 'exists:support_teams,id'],
        ]);

        $canned = CannedResponse::updateOrCreate(
            ['id' => $data['id'] ?? null],
            collect($data)->except('id')->all() + [
                'organization_id' => app('current.organization_id'),
                'created_by'      => $request->user()?->id,
            ],
        );

        return response()->json(['data' => $canned], 201);
    }

    /** Fill one in against a ticket, and count that it was used. */
    public function useCanned(Request $request, int $canned): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        $data = $request->validate(['ticket_id' => ['required', 'integer']]);

        $response = CannedResponse::findOrFail($canned);
        $ticket   = Ticket::findOrFail($data['ticket_id']);

        $response->increment('use_count');
        $response->forceFill(['last_used_at' => now()])->save();

        return response()->json(['body' => $response->render($ticket)]);
    }

    public function destroyCanned(int $canned): JsonResponse
    {
        $this->authorize('update', Ticket::class);

        CannedResponse::findOrFail($canned)->delete();

        return response()->json(['deleted' => true]);
    }

    // ── Saved queues ──────────────────────────────────────────────────────────

    public function saveView(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Ticket::class);

        $data = $request->validate([
            'name'    => ['required', 'string', 'max:120'],
            'filters' => ['required', 'array'],
            'shared'  => ['boolean'],
        ]);

        $view = SavedView::create([
            'organization_id' => app('current.organization_id'),
            // A shared view belongs to the desk; anything else is personal.
            'user_id' => ($data['shared'] ?? false) ? null : $request->user()?->id,
            'name'    => $data['name'],
            'filters' => $data['filters'],
        ]);

        return response()->json(['data' => $view], 201);
    }

    public function destroyView(Request $request, int $view): JsonResponse
    {
        $this->authorize('viewAny', Ticket::class);

        $record = SavedView::findOrFail($view);

        // A shared view is the desk's, not one person's, so removing it takes
        // the permission that covers desk configuration.
        if ($record->isShared()) {
            $this->authorize('manageConfig', Ticket::class);
        } elseif ($record->user_id !== $request->user()?->id) {
            abort(403, 'That view belongs to somebody else.');
        }

        $record->delete();

        return response()->json(['deleted' => true]);
    }
}
