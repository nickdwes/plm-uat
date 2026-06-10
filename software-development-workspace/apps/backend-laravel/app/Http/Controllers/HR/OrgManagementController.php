<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Designation;
use App\Models\JobCategory;
use App\Models\JobGrade;
use App\Models\OrgFunctionalUnit;
use App\Models\OrgBusinessUnit;
use App\Models\OrgPractice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrgManagementController extends Controller
{
    // ── Designations ─────────────────────────────────────────────────────────

    public function designations(): JsonResponse
    {
        $orgId = app('current.organization_id');
        $data  = Designation::where('organization_id', $orgId)
            ->orderBy('title')
            ->get();
        return response()->json(['data' => $data]);
    }

    public function storeDesignation(Request $request): JsonResponse
    {
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
        $data = $request->validate([
            'title'       => 'sometimes|string|max:120',
            'description' => 'nullable|string|max:500',
        ]);
        $designation->update($data);
        return response()->json($designation);
    }

    public function destroyDesignation(Designation $designation): JsonResponse
    {
        $designation->delete();
        return response()->json(null, 204);
    }

    // ── Job Grades ───────────────────────────────────────────────────────────

    public function jobGrades(): JsonResponse
    {
        $orgId = app('current.organization_id');
        $data  = JobGrade::where('organization_id', $orgId)
            ->orderBy('level')
            ->get();
        return response()->json(['data' => $data]);
    }

    public function storeJobGrade(Request $request): JsonResponse
    {
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
        $jobGrade->delete();
        return response()->json(null, 204);
    }

    // ── Job Categories ───────────────────────────────────────────────────────

    public function jobCategories(): JsonResponse
    {
        $orgId = app('current.organization_id');
        $data  = JobCategory::where('organization_id', $orgId)
            ->orderBy('name')
            ->get();
        return response()->json(['data' => $data]);
    }

    public function storeJobCategory(Request $request): JsonResponse
    {
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
        $data = $request->validate([
            'name'        => 'sometimes|string|max:120',
            'description' => 'nullable|string|max:500',
        ]);
        $jobCategory->update($data);
        return response()->json($jobCategory);
    }

    public function destroyJobCategory(JobCategory $jobCategory): JsonResponse
    {
        $jobCategory->delete();
        return response()->json(null, 204);
    }

    // ── Org Structure: Full hierarchy tree ───────────────────────────────────

    public function orgStructure(): JsonResponse
    {
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
        $orgId = app('current.organization_id');
        return response()->json(['data' => OrgFunctionalUnit::where('organization_id', $orgId)->orderBy('name')->get()]);
    }

    public function storeFunctionalUnit(Request $request): JsonResponse
    {
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
        $functionalUnit->delete();
        return response()->json(null, 204);
    }

    // ── Departments CRUD (updated to support functional_unit_id & lead) ──────

    public function departments(): JsonResponse
    {
        $orgId = app('current.organization_id');
        $data  = Department::where('organization_id', $orgId)
            ->withCount(['employees as headcount' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('name')
            ->get();
        return response()->json(['data' => $data]);
    }

    public function storeDepartment(Request $request): JsonResponse
    {
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
        $department->delete();
        return response()->json(null, 204);
    }

    // ── Business Units CRUD ──────────────────────────────────────────────────

    public function storeBusinessUnit(Request $request): JsonResponse
    {
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
        $businessUnit->delete();
        return response()->json(null, 204);
    }

    // ── Practices CRUD ───────────────────────────────────────────────────────

    public function storePractice(Request $request): JsonResponse
    {
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
        $practice->delete();
        return response()->json(null, 204);
    }
}
