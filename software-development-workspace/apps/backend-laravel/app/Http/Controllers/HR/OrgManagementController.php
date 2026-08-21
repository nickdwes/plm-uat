<?php

namespace App\Http\Controllers\HR;

use App\Support\Authorization\Permission;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Designation;
use App\Models\JobCategory;
use App\Models\JobGrade;
use App\Models\OrgFunctionalUnit;
use App\Models\OrgBusinessUnit;
use App\Models\OrgPractice;
use Illuminate\Http\JsonResponse;
use App\Models\Asset\Asset;
use App\Models\Asset\AssetCategory;
use App\Models\Asset\AssetCategoryField;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrgManagementController extends Controller
{
    // ── Designations ─────────────────────────────────────────────────────────

    public function designations(): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_VIEW), 403);

        $orgId = app('current.organization_id');
        $data  = Designation::where('organization_id', $orgId)
            ->orderBy('title')
            ->get();
        return response()->json(['data' => $data]);
    }

    public function storeDesignation(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $data = $request->validate([
            'title'       => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
        ]);
        $desig = Designation::create(array_merge($data, [
            'organization_id' => app('current.organization_id'),
        ]));
        return response()->json($desig, 201);
    }

    public function updateDesignation(Request $request, Designation $designation): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $data = $request->validate([
            'title'       => 'sometimes|string|max:120',
            'description' => 'nullable|string|max:500',
        ]);
        $designation->update($data);
        return response()->json($designation);
    }

    public function destroyDesignation(Designation $designation): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $designation->delete();
        return response()->json(null, 204);
    }

    // ── Job Grades ───────────────────────────────────────────────────────────

    public function jobGrades(): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_VIEW), 403);

        $orgId = app('current.organization_id');
        $data  = JobGrade::where('organization_id', $orgId)
            ->orderBy('level')
            ->get();
        return response()->json(['data' => $data]);
    }

    public function storeJobGrade(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $data = $request->validate([
            'name'       => 'required|string|max:80',
            'level'      => 'required|integer|min:1',
            'min_salary' => 'nullable|numeric|min:0',
            'max_salary' => 'nullable|numeric|min:0',
        ]);
        $grade = JobGrade::create(array_merge($data, [
            'organization_id' => app('current.organization_id'),
        ]));
        return response()->json($grade, 201);
    }

    public function updateJobGrade(Request $request, JobGrade $jobGrade): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $data = $request->validate([
            'name'       => 'sometimes|string|max:80',
            'level'      => 'sometimes|integer|min:1',
            'min_salary' => 'nullable|numeric|min:0',
            'max_salary' => 'nullable|numeric|min:0',
        ]);
        $jobGrade->update($data);
        return response()->json($jobGrade);
    }

    public function destroyJobGrade(JobGrade $jobGrade): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $jobGrade->delete();
        return response()->json(null, 204);
    }

    // ── Job Categories ───────────────────────────────────────────────────────

    // ── Asset categories ──────────────────────────────────────────────────

    /**
     * The kinds of thing this organisation owns.
     *
     * Gated on the asset permissions rather than the department ones: deciding
     * that the company tracks UPS units is an asset decision, and the person
     * who does it is not necessarily allowed near the org chart.
     */
    public function assetCategories(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::ASSETS_VIEW), 403);

        $q = AssetCategory::query()->orderBy('asset_class')->orderBy('sort_order')->orderBy('name');

        // The picker asks for one class and only what it can offer; the
        // configuration screen wants everything, including what is switched off.
        if ($class = $request->string('asset_class')->toString()) {
            $q->where('asset_class', $class);
        }

        if ($request->boolean('usable')) {
            $q->where('is_active', true);
        }

        return response()->json(['data' => $q->get()]);
    }

    /**
     * Everything an asset of this class and category is asked.
     *
     * The class first, then the category — the registration form needs both and
     * should not have to know that they come from two places or how they are
     * ordered against each other.
     */
    public function assetFormFields(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::ASSETS_VIEW), 403);

        $class = $request->string('asset_class')->toString() ?: 'physical';
        $name  = $request->string('category')->toString();

        $category = $name === '' ? null : AssetCategory::where('asset_class', $class)
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->first();

        $fields = AssetCategoryField::query()
            ->where(function ($q) use ($class, $category) {
                $q->where(fn ($c) => $c->whereNull('asset_category_id')->where('asset_class', $class));

                if ($category) {
                    $q->orWhere('asset_category_id', $category->id);
                }
            })
            ->orderByRaw('CASE WHEN asset_category_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('section_order')->orderBy('sort_order')->orderBy('id')
            ->get();

        return response()->json(['data' => $fields]);
    }

    public function storeAssetCategory(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::ASSETS_CREATE), 403);

        $orgId = app('current.organization_id');

        $data = $request->validate([
            'asset_class' => ['required', Rule::in(Asset::CLASSES)],
            'name'        => [
                'required', 'string', 'max:120',
                // Twice in one class is a typo, not a decision.
                Rule::unique('asset_categories', 'name')
                    ->where(fn ($q) => $q->where('organization_id', $orgId)
                        ->where('asset_class', $request->input('asset_class'))),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order'  => ['nullable', 'integer', 'min:0', 'max:999'],
            /*
             * How this kind of thing is held: carried by a person, looked after
             * by one, or consumed as seats. Absent before, so every category
             * created on this screen silently defaulted to "carried" — and a
             * new core-switch category would then have appeared in the assign
             * picker, putting racked equipment on a leaver's checklist.
             */
            'custody_model' => ['nullable', Rule::in(AssetCategory::CUSTODY_MODELS)],
        ]);

        $category = AssetCategory::create($data + ['organization_id' => $orgId]);

        return response()->json(['data' => $category], 201);
    }

    public function updateAssetCategory(Request $request, AssetCategory $assetCategory): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::ASSETS_UPDATE), 403);

        $assetCategory->update($request->validate([
            'name'        => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active'   => ['sometimes', 'boolean'],
            'sort_order'  => ['sometimes', 'integer', 'min:0', 'max:999'],
            // Changeable after the fact: the 56 categories currently marked
            // custodian were set by a migration, and nobody could correct one.
            'custody_model' => ['sometimes', Rule::in(AssetCategory::CUSTODY_MODELS)],
        ]));

        return response()->json(['data' => $assetCategory->fresh()]);
    }

    /**
     * Switched off, or removed if nothing was ever filed under it.
     *
     * Assets record a category by name. Deleting one that is in use would
     * leave those rows pointing at something that no longer means anything, so
     * the delete quietly becomes a retirement — and says so.
     */
    public function destroyAssetCategory(AssetCategory $assetCategory): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::ASSETS_DELETE), 403);

        $inUse = Asset::where('category', $assetCategory->name)
            ->where('asset_class', $assetCategory->asset_class)
            ->exists();

        if ($inUse) {
            $assetCategory->update(['is_active' => false]);

            return response()->json([
                'message' => 'Assets are filed under this category, so it has been switched off rather than deleted. It will stop appearing when registering something new.',
                'retired' => true,
            ]);
        }

        $assetCategory->delete();

        return response()->json(['message' => 'Category removed.', 'retired' => false]);
    }

    // ── Fields captured per category ──────────────────────────────────────

    public function categoryFields(AssetCategory $assetCategory): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::ASSETS_VIEW), 403);

        return response()->json([
            'data' => $assetCategory->fields()->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    /**
     * Add a field to a category.
     *
     * The key is generated from the label and never taken from the caller.
     * Answers are stored under it, so letting somebody choose or change it
     * would eventually orphan every value already captured.
     */
    public function storeCategoryField(Request $request, AssetCategory $assetCategory): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::ASSETS_CREATE), 403);

        $data = $request->validate([
            'label'       => ['required', 'string', 'max:120'],
            'type'        => ['required', Rule::in(AssetCategoryField::TYPES)],
            'options'     => ['nullable', 'array'],
            'options.*'   => ['string', 'max:120'],
            'help'        => ['nullable', 'string', 'max:255'],
            'is_required' => ['nullable', 'boolean'],
            'sort_order'  => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $key = AssetCategoryField::keyFor($data['label']);
        abort_if($key === '', 422, 'That label does not make a usable field name.');

        abort_if(
            $assetCategory->fields()->where('key', $key)->exists(),
            422,
            'A field with that name already exists on this category.',
        );

        // A select with nothing to select from is a text box that refuses
        // everything.
        abort_if(
            $data['type'] === 'select' && empty($data['options']),
            422,
            'A list field needs at least one option.',
        );

        $field = $assetCategory->fields()->create($data + [
            'organization_id' => app('current.organization_id'),
            'key'             => $key,
        ]);

        return response()->json(['data' => $field], 201);
    }

    /** The label, help and ordering can change. The key cannot. */
    public function updateCategoryField(Request $request, AssetCategory $assetCategory, int $field): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::ASSETS_UPDATE), 403);

        $record = $assetCategory->fields()->findOrFail($field);

        $record->update($request->validate([
            'label'       => ['sometimes', 'string', 'max:120'],
            'help'        => ['nullable', 'string', 'max:255'],
            'options'     => ['nullable', 'array'],
            'options.*'   => ['string', 'max:120'],
            'is_required' => ['sometimes', 'boolean'],
            'sort_order'  => ['sometimes', 'integer', 'min:0', 'max:999'],
        ]));

        return response()->json(['data' => $record->fresh()]);
    }

    /**
     * Remove a field.
     *
     * Values already captured under its key are left on the assets rather than
     * scrubbed. Removing the question does not make the answers untrue, and a
     * field deleted by mistake can be added back with the same label and find
     * its data waiting.
     */
    public function destroyCategoryField(AssetCategory $assetCategory, int $field): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::ASSETS_DELETE), 403);

        $assetCategory->fields()->findOrFail($field)->delete();

        return response()->json(['message' => 'Field removed. Answers already recorded are kept on the assets.']);
    }

    public function jobCategories(): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_VIEW), 403);

        $orgId = app('current.organization_id');
        $data  = JobCategory::where('organization_id', $orgId)
            ->orderBy('name')
            ->get();
        return response()->json(['data' => $data]);
    }

    public function storeJobCategory(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
        ]);
        $category = JobCategory::create(array_merge($data, [
            'organization_id' => app('current.organization_id'),
        ]));
        return response()->json($category, 201);
    }

    public function updateJobCategory(Request $request, JobCategory $jobCategory): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:120',
            'description' => 'nullable|string|max:500',
        ]);
        $jobCategory->update($data);
        return response()->json($jobCategory);
    }

    public function destroyJobCategory(JobCategory $jobCategory): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $jobCategory->delete();
        return response()->json(null, 204);
    }

    // ── Org Structure: Full hierarchy tree ───────────────────────────────────

    /**
     * The org chart, both ways round.
     *
     * "Org chart" means two different pictures depending on who is asking. HR
     * means the structure — units, departments, who heads them. A manager means
     * the reporting line — who answers to whom. They disagree often enough to
     * be worth showing separately: somebody can head a department and report
     * to a person outside it.
     *
     * Both are returned in one call because the tab shows one and offers the
     * other, and a second round trip to switch view is a spinner nobody needs.
     */
    public function orgChart(): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_VIEW), 403);

        $orgId = app('current.organization_id');

        /*
         * Everybody who works here, employed or placed. Somebody seconded in
         * used to be missing from the chart entirely while their placement sat
         * in the database carrying a department and a manager.
         */
        $people = \App\Models\Employee::forOrganization($orgId)
            ->where('status', 'active')
            ->whereNull('date_exited')
            ->with(['designation:id,title', 'department:id,name'])
            ->orderBy('first_name')
            ->get([
                'id', 'employee_number', 'first_name', 'last_name', 'reports_to',
                'designation_id', 'department_id', 'work_email', 'photo_url', 'is_shared',
                'primary_organization_id',
            ]);

        \App\Models\Employee::attachPlacements($people, $orgId);

        return response()->json([
            'reporting'   => $this->reportingTree($people, $orgId),
            'unassigned'  => $this->peopleWithoutAPlace($people),
            'headcount'   => $people->count(),
            'organization' => \App\Models\Organization::find($orgId)?->only(['id', 'name']),
        ]);
    }

    /**
     * The reporting line as a tree.
     *
     * Assembled from one query rather than recursing into the database, because
     * a chart that costs a query per person is a chart that times out on the
     * organisation big enough to need one.
     */
    private function reportingTree($people, ?int $orgId = null): array
    {
        $byManager = $people->groupBy('reports_to');
        $ids       = $people->pluck('id')->flip();

        /*
         * Roots are people with no manager *and* people whose manager is not in
         * this list — somebody reporting into another tenant, or to a leaver.
         * Without the second case they vanish from the chart entirely, which is
         * how an org chart quietly loses a department.
         */
        $roots = $people->filter(
            fn ($e) => $e->reports_to === null || ! $ids->has($e->reports_to)
        );

        $seen = [];

        $build = function ($employee, int $depth) use (&$build, $byManager, &$seen, $orgId) {
            /*
             * A reports_to cycle is rare and fatal: two people managing each
             * other would recurse until the request dies. Guarded rather than
             * assumed away, because the data is edited by hand.
             */
            if (isset($seen[$employee->id]) || $depth > 20) {
                return null;
            }

            $seen[$employee->id] = true;

            $children = ($byManager[$employee->id] ?? collect())
                ->map(fn ($child) => $build($child, $depth + 1))
                ->filter()
                ->values();

            return [
                'id'          => $employee->id,
                'name'        => trim($employee->first_name . ' ' . $employee->last_name),
                'number'      => $employee->employee_number,
                // What they do here, falling back to their own record.
                'title'       => $employee->host_title ?? $employee->designation?->title,
                'department'  => $employee->host_department ?? $employee->department?->name,
                'email'       => $employee->work_email,
                'photo'       => $employee->photo_url,
                'is_shared'   => (bool) $employee->is_shared,
                // Placed here rather than employed here. Different from
                // is_shared, which says somebody works across organisations
                // without saying whose payroll they are on.
                'is_visiting' => $employee->isVisiting($orgId),
                // The whole subtree, so a collapsed node can say what it hides.
                'reports'     => $this->countDescendants($children),
                'children'    => $children,
            ];
        };

        return $roots->map(fn ($r) => $build($r, 0))->filter()->values()->all();
    }

    private function countDescendants($children): int
    {
        return collect($children)->sum(fn ($c) => 1 + $c['reports']);
    }

    /**
     * Active people with no department, which a chart should surface not hide.
     *
     * A visiting colleague's department is the one their placement gives them
     * here, not the one on their own record — so somebody seconded into a real
     * department is placed, even though their `department_id` points at their
     * home organisation's.
     */
    private function peopleWithoutAPlace($people): array
    {
        return $people
            ->filter(fn ($e) => ($e->host_department ?? $e->department?->name) === null)
            ->map(fn ($e) => [
                'id'     => $e->id,
                'name'   => trim($e->first_name . ' ' . $e->last_name),
                'number' => $e->employee_number,
                'title'  => $e->host_title ?? $e->designation?->title,
            ])->values()->all();
    }

    public function orgStructure(): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_VIEW), 403);

        $orgId = app('current.organization_id');

        $units = OrgFunctionalUnit::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'description']);

        $departments = Department::where('organization_id', $orgId)
            ->withCount(['employees as headcount' => fn ($q) => $q->where('status', 'active')])
            ->with(['lead:id,first_name,last_name,employee_number'])
            ->orderBy('name')
            ->get(['id', 'functional_unit_id', 'name', 'code', 'cost_center', 'description', 'lead_employee_id', 'is_active']);

        $businessUnits = OrgBusinessUnit::where('organization_id', $orgId)
            ->with(['lead:id,first_name,last_name,employee_number'])
            ->orderBy('name')
            ->get(['id', 'department_id', 'name', 'code', 'description', 'lead_employee_id', 'is_active']);

        $practices = OrgPractice::where('organization_id', $orgId)
            ->with(['lead:id,first_name,last_name,employee_number'])
            ->orderBy('name')
            ->get(['id', 'business_unit_id', 'name', 'code', 'description', 'lead_employee_id', 'is_active']);

        // Index by parent id for O(n) tree assembly
        $buByDept     = $businessUnits->groupBy('department_id');
        $practByBU    = $practices->groupBy('business_unit_id');
        $deptByUnit   = $departments->groupBy('functional_unit_id');

        $tree = $units->map(function ($unit) use ($deptByUnit, $buByDept, $practByBU) {
            $deps = ($deptByUnit[$unit->id] ?? collect())->map(function ($dept) use ($buByDept, $practByBU) {
                $bus = ($buByDept[$dept->id] ?? collect())->map(function ($bu) use ($practByBU) {
                    $bu->practices = $practByBU[$bu->id] ?? collect();
                    return $bu;
                });
                $dept->business_units = $bus;
                return $dept;
            });
            $unit->departments = $deps;
            return $unit;
        });

        // Departments not assigned to any functional unit
        $unassigned = ($deptByUnit[null] ?? collect())->map(function ($dept) use ($buByDept, $practByBU) {
            $bus = ($buByDept[$dept->id] ?? collect())->map(function ($bu) use ($practByBU) {
                $bu->practices = $practByBU[$bu->id] ?? collect();
                return $bu;
            });
            $dept->business_units = $bus;
            return $dept;
        });

        return response()->json([
            'functional_units' => $tree,
            'unassigned_departments' => $unassigned,
        ]);
    }

    // ── Functional Units CRUD ────────────────────────────────────────────────

    public function functionalUnits(): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_VIEW), 403);

        $orgId = app('current.organization_id');
        return response()->json(['data' => OrgFunctionalUnit::where('organization_id', $orgId)->orderBy('name')->get()]);
    }

    public function storeFunctionalUnit(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'code'        => 'nullable|string|max:20',
            'description' => 'nullable|string|max:300',
        ]);
        $unit = OrgFunctionalUnit::create(array_merge($data, ['organization_id' => app('current.organization_id')]));
        return response()->json($unit, 201);
    }

    public function updateFunctionalUnit(Request $request, OrgFunctionalUnit $functionalUnit): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:120',
            'code'        => 'nullable|string|max:20',
            'description' => 'nullable|string|max:300',
            'is_active'   => 'nullable|boolean',
        ]);
        $functionalUnit->update($data);
        return response()->json($functionalUnit);
    }

    public function destroyFunctionalUnit(OrgFunctionalUnit $functionalUnit): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $functionalUnit->delete();
        return response()->json(null, 204);
    }

    // ── Departments CRUD (updated to support functional_unit_id & lead) ──────

    public function departments(): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_VIEW), 403);

        $orgId = app('current.organization_id');
        $data  = Department::where('organization_id', $orgId)
            ->withCount(['employees as headcount' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('name')
            ->get();
        return response()->json(['data' => $data]);
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $orgId = app('current.organization_id');
        $data = $request->validate([
            'name'               => 'required|string|max:120',
            'cost_center'        => 'nullable|string|max:50',
            'description'        => 'nullable|string|max:300',
            'functional_unit_id' => ['nullable', 'integer', Rule::exists('org_functional_units', 'id')->where('organization_id', $orgId)],
            'lead_employee_id'   => ['nullable', 'integer', Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],
        ]);
        $dept = Department::create(array_merge($data, ['organization_id' => $orgId]));
        return response()->json($dept, 201);
    }

    public function updateDepartment(Request $request, Department $department): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $orgId = app('current.organization_id');
        $data = $request->validate([
            'name'               => 'sometimes|string|max:120',
            'cost_center'        => 'nullable|string|max:50',
            'description'        => 'nullable|string|max:300',
            'functional_unit_id' => ['nullable', 'integer', Rule::exists('org_functional_units', 'id')->where('organization_id', $orgId)],
            'lead_employee_id'   => ['nullable', 'integer', Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],
        ]);
        $department->update($data);
        return response()->json($department);
    }

    public function destroyDepartment(Department $department): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $department->delete();
        return response()->json(null, 204);
    }

    // ── Business Units CRUD ──────────────────────────────────────────────────

    public function storeBusinessUnit(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $orgId = app('current.organization_id');
        $data = $request->validate([
            'department_id'    => ['required', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
            'name'             => 'required|string|max:120',
            'code'             => 'nullable|string|max:20',
            'description'      => 'nullable|string|max:300',
            'lead_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],
        ]);
        $bu = OrgBusinessUnit::create(array_merge($data, ['organization_id' => $orgId]));
        return response()->json($bu->load('lead:id,first_name,last_name,employee_number'), 201);
    }

    public function updateBusinessUnit(Request $request, OrgBusinessUnit $businessUnit): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $orgId = app('current.organization_id');
        $data = $request->validate([
            'name'             => 'sometimes|string|max:120',
            'code'             => 'nullable|string|max:20',
            'description'      => 'nullable|string|max:300',
            'lead_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],
            'is_active'        => 'nullable|boolean',
        ]);
        $businessUnit->update($data);
        return response()->json($businessUnit->load('lead:id,first_name,last_name,employee_number'));
    }

    public function destroyBusinessUnit(OrgBusinessUnit $businessUnit): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $businessUnit->delete();
        return response()->json(null, 204);
    }

    // ── Practices CRUD ───────────────────────────────────────────────────────

    public function storePractice(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $orgId = app('current.organization_id');
        $data = $request->validate([
            'business_unit_id' => ['required', 'integer', Rule::exists('org_business_units', 'id')->where('organization_id', $orgId)],
            'name'             => 'required|string|max:120',
            'code'             => 'nullable|string|max:20',
            'description'      => 'nullable|string|max:300',
            'lead_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],
        ]);
        $practice = OrgPractice::create(array_merge($data, ['organization_id' => $orgId]));
        return response()->json($practice->load('lead:id,first_name,last_name,employee_number'), 201);
    }

    public function updatePractice(Request $request, OrgPractice $practice): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $orgId = app('current.organization_id');
        $data = $request->validate([
            'name'             => 'sometimes|string|max:120',
            'code'             => 'nullable|string|max:20',
            'description'      => 'nullable|string|max:300',
            'lead_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('primary_organization_id', $orgId)],
            'is_active'        => 'nullable|boolean',
        ]);
        $practice->update($data);
        return response()->json($practice->load('lead:id,first_name,last_name,employee_number'));
    }

    public function destroyPractice(OrgPractice $practice): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission(Permission::DEPARTMENTS_MANAGE), 403);

        $practice->delete();
        return response()->json(null, 204);
    }
}
