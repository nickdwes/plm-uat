<?php

namespace App\Http\Controllers\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pipeline\ResearchResource;
use App\Models\Pipeline\Research;
use App\Models\Pipeline\ResearchTeamMember;
use App\Support\Account\ReferenceSequence;
use App\Support\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Work done to find out whether the thing can be built.
 *
 * The legacy linked research to a customer on 18 of 20 rows and to a lead,
 * opportunity or project on none of them — so the link to a deal is new, and
 * all four stay optional. Research genuinely does start before there is
 * anything to attach it to, which is most of the point of doing it.
 */
class ResearchController extends Controller
{
    private const DETAIL = ['account', 'lead', 'opportunity', 'researchLead', 'team.employee'];

    /** Someone holding only `view_own` sees what they lead or are on. */
    private function limitToOwn($q, Request $request): void
    {
        if ($request->user()->hasPermission(Permission::PIPELINE_VIEW)) {
            return;
        }

        $employeeId = $request->user()->employeeId();

        $q->where(fn ($w) => $w->where('research_lead_id', $employeeId)
            ->orWhereHas('team', fn ($t) => $t->where('employee_id', $employeeId)));
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Research::class);

        $q = Research::query()->with(['account', 'researchLead', 'opportunity', 'team.employee']);

        $this->limitToOwn($q, $request);

        foreach (['account_id', 'lead_id', 'opportunity_id'] as $key) {
            if ($id = $request->integer($key)) {
                $q->where($key, $id);
            }
        }

        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }

        if ($term = trim((string) $request->query('search', ''))) {
            $q->where('name', 'ilike', '%' . $term . '%');
        }

        return ResearchResource::collection(
            $q->latest('id')->paginate((int) $request->input('per_page', 25))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Research::class);

        $orgId = app('current.organization_id');
        $data  = $this->rules($request, $orgId);

        $research = Research::create($data + [
            'reference'       => ReferenceSequence::next('RS', 'researches', $orgId),
            'organization_id' => $orgId,
            'created_by'      => $request->user()->id,
        ]);

        /*
         * A research card raised against a deal inherits the deal's customer.
         * Asking for it again would invite the two disagreeing, and the answer
         * is already known.
         */
        if ($research->opportunity && ! $research->account_id) {
            $research->update(['account_id' => $research->opportunity->account_id]);
        }

        return (new ResearchResource($research->load(self::DETAIL)))->response()->setStatusCode(201);
    }

    public function show(Research $research): ResearchResource
    {
        $this->authorize('view', $research);

        return new ResearchResource($research->load(self::DETAIL));
    }

    public function update(Request $request, Research $research): ResearchResource
    {
        $this->authorize('update', $research);

        $research->update($this->rules($request, $research->organization_id));

        return new ResearchResource($research->fresh(self::DETAIL));
    }

    public function destroy(Research $research): JsonResponse
    {
        $this->authorize('delete', $research);
        $research->delete();

        return response()->json(['message' => 'Research removed.']);
    }

    // ── Team ──────────────────────────────────────────────────────────────

    public function storeTeamMember(Request $request, Research $research): ResearchResource
    {
        $this->authorize('update', $research);

        $data = $request->validate([
            'employee_id' => ['required', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $research->organization_id)],
            'role' => ['nullable', 'string', 'max:120'],
        ]);

        ResearchTeamMember::updateOrCreate(
            ['research_id' => $research->id, 'employee_id' => $data['employee_id']],
            ['organization_id' => $research->organization_id, 'role' => $data['role'] ?? null],
        );

        return new ResearchResource($research->fresh(self::DETAIL));
    }

    public function destroyTeamMember(Research $research, ResearchTeamMember $member): ResearchResource
    {
        $this->authorize('update', $research);
        abort_unless($member->research_id === $research->id, 404);

        $member->delete();

        return new ResearchResource($research->fresh(self::DETAIL));
    }

    private function rules(Request $request, int $orgId): array
    {
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where('organization_id', $orgId);

        return $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            // The plan for the work, as distinct from what came back.
            'script'      => ['nullable', 'string', 'max:20000'],
            'findings'    => ['nullable', 'string', 'max:20000'],

            'status'   => ['sometimes', Rule::in(Research::STATUSES)],
            'priority' => ['sometimes', Rule::in(Research::PRIORITIES)],
            'deadline' => ['nullable', 'date'],

            // All optional and independent: research starts before there is
            // anything to attach it to, which is most of the point.
            'account_id'     => ['nullable', 'integer', $scoped('accounts')],
            'lead_id'        => ['nullable', 'integer', $scoped('leads')],
            'opportunity_id' => ['nullable', 'integer', $scoped('opportunities')],

            'research_lead_id' => ['nullable', 'integer',
                Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],

            'covers_concept'      => ['nullable', 'boolean'],
            'covers_architecture' => ['nullable', 'boolean'],
            'covers_integration'  => ['nullable', 'boolean'],
        ]);
    }
}
