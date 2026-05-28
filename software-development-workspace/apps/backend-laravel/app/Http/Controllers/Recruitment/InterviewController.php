<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Interview;
use App\Models\Recruitment\InterviewPanelist;
use App\Models\Recruitment\InterviewScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class InterviewController extends Controller
{
    private function orgId(): int
    {
        return (int) app('current.organization_id');
    }

    // ── Formatting helpers ────────────────────────────────────────────────────

    private function fmtInterview(Interview $i, bool $withScores = false): array
    {
        $panelists = $i->panelists->map(fn($p) => [
            'id'            => $p->id,
            'employee_id'   => $p->employee_id,
            'name'          => $p->employee ? trim("{$p->employee->first_name} {$p->employee->last_name}") : 'Unknown',
            'work_email'    => $p->employee?->work_email,
            'role'          => $p->role,
            'invite_sent'   => $p->invite_sent,
            'invite_sent_at'=> $p->invite_sent_at?->toIso8601String(),
            'confirmed'     => $p->confirmed,
        ]);

        $data = [
            'id'               => $i->id,
            'application_id'   => $i->application_id,
            'vacancy_id'       => $i->vacancy_id,
            'title'            => $i->title,
            'interview_type'   => $i->interview_type,
            'scheduled_at'     => $i->scheduled_at?->toIso8601String(),
            'duration_minutes' => $i->duration_minutes,
            'location'         => $i->location,
            'agenda'           => $i->agenda,
            'status'           => $i->status,
            'overall_notes'    => $i->overall_notes,
            'overall_score'    => $i->overall_score,
            'recommendation'   => $i->recommendation,
            'panelists'        => $panelists,
            'created_at'       => $i->created_at?->toIso8601String(),
        ];

        if ($withScores) {
            $data['scores'] = $i->scores->map(fn($s) => $this->fmtScore($s))->values();
        }

        return $data;
    }

    private function fmtScore(InterviewScore $s): array
    {
        return [
            'id'                      => $s->id,
            'interview_id'            => $s->interview_id,
            'panelist_id'             => $s->panelist_id,
            'panelist_name'           => $s->panelist ? trim("{$s->panelist->first_name} {$s->panelist->last_name}") : 'Unknown',
            'personal_presentation'   => $s->personal_presentation,
            'training_certifications' => $s->training_certifications,
            'role_knowledge'          => $s->role_knowledge,
            'problem_solving'         => $s->problem_solving,
            'teamskills_leadership'   => $s->teamskills_leadership,
            'attitude_adaptability'   => $s->attitude_adaptability,
            'personal_values'         => $s->personal_values,
            'interests_goals'         => $s->interests_goals,
            'total'                   => $s->total,
            'average_score'           => $s->average_score,
            'strengths'               => $s->strengths,
            'weaknesses'              => $s->weaknesses,
            'remarks'                 => $s->remarks,
            'recommendation'          => $s->recommendation,
            'submitted'               => $s->submitted,
            'submitted_at'            => $s->submitted_at?->toIso8601String(),
        ];
    }

    // ── List interviews for an application ────────────────────────────────────

    public function index(Request $request, int $applicationId): JsonResponse
    {
        $application = Application::findOrFail($applicationId);

        $interviews = Interview::where('application_id', $applicationId)
            ->where('organization_id', $this->orgId())
            ->with(['panelists.employee', 'scores.panelist'])
            ->orderByDesc('scheduled_at')
            ->get();

        return response()->json([
            'data' => $interviews->map(fn($i) => $this->fmtInterview($i, true))->values(),
        ]);
    }

    // ── Get single interview ──────────────────────────────────────────────────

    public function show(Interview $interview): JsonResponse
    {
        abort_if($interview->organization_id !== $this->orgId(), 403);
        $interview->load(['panelists.employee', 'scores.panelist']);
        return response()->json($this->fmtInterview($interview, true));
    }

    // ── Schedule a new interview ──────────────────────────────────────────────

    public function store(Request $request, int $applicationId): JsonResponse
    {
        $application = Application::with('candidate', 'vacancy')->findOrFail($applicationId);

        $v = $request->validate([
            'title'             => 'required|string|max:200',
            'interview_type'    => 'required|in:phone,video,onsite,panel,technical,hr',
            'scheduled_at'      => 'required|date',
            'duration_minutes'  => 'required|integer|min:15|max:480',
            'location'          => 'nullable|string|max:300',
            'agenda'            => 'nullable|string|max:5000',
            'panelist_ids'      => 'required|array|min:1',
            'panelist_ids.*'    => 'integer|exists:employees,id',
            'panelist_roles'    => 'nullable|array',
            'panelist_roles.*'  => 'in:lead,panelist,observer',
        ]);

        $interview = Interview::create([
            'application_id'   => $applicationId,
            'vacancy_id'       => $application->vacancy_id,
            'organization_id'  => $this->orgId(),
            'title'            => $v['title'],
            'interview_type'   => $v['interview_type'],
            'scheduled_at'     => $v['scheduled_at'],
            'duration_minutes' => $v['duration_minutes'],
            'location'         => $v['location'] ?? null,
            'agenda'           => $v['agenda'] ?? null,
            'status'           => 'scheduled',
        ]);

        // Add panelists
        $roles = $v['panelist_roles'] ?? [];
        foreach ($v['panelist_ids'] as $idx => $empId) {
            InterviewPanelist::create([
                'interview_id' => $interview->id,
                'employee_id'  => $empId,
                'role'         => $roles[$idx] ?? 'panelist',
            ]);
        }

        $interview->load(['panelists.employee', 'scores.panelist']);
        return response()->json($this->fmtInterview($interview, true), 201);
    }

    // ── Update interview details ──────────────────────────────────────────────

    public function update(Request $request, Interview $interview): JsonResponse
    {
        abort_if($interview->organization_id !== $this->orgId(), 403);

        $v = $request->validate([
            'title'            => 'sometimes|string|max:200',
            'interview_type'   => 'sometimes|in:phone,video,onsite,panel,technical,hr',
            'scheduled_at'     => 'sometimes|date',
            'duration_minutes' => 'sometimes|integer|min:15|max:480',
            'location'         => 'nullable|string|max:300',
            'agenda'           => 'nullable|string|max:5000',
            'status'           => 'sometimes|in:scheduled,confirmed,completed,cancelled,rescheduled',
            'overall_notes'    => 'nullable|string|max:5000',
            'recommendation'   => 'nullable|in:hire,hold,reject',
        ]);

        $interview->update($v);
        $interview->load(['panelists.employee', 'scores.panelist']);
        return response()->json($this->fmtInterview($interview, true));
    }

    // ── Send invites to panelists ─────────────────────────────────────────────

    public function sendInvites(Request $request, Interview $interview): JsonResponse
    {
        abort_if($interview->organization_id !== $this->orgId(), 403);

        $interview->load(['panelists.employee', 'scores']);
        $application = Application::with('candidate', 'vacancy')->find($interview->application_id);
        $candidateName = $application?->candidate
            ? trim("{$application->candidate->first_name} {$application->candidate->last_name}")
            : 'Candidate';
        $vacancyName = $application?->vacancy?->name ?? 'the role';

        $sent  = 0;
        $errors = [];

        foreach ($interview->panelists as $panelist) {
            $email = $panelist->employee?->work_email;
            if (! $email) continue;

            $name    = trim("{$panelist->employee->first_name} {$panelist->employee->last_name}");
            $dateStr = $interview->scheduled_at->format('l, d F Y \a\t H:i');
            $dur     = $interview->duration_minutes;

            $body = "Dear {$name},\n\n"
                . "You have been invited to participate as an interview panelist ({$panelist->role}) for the following interview:\n\n"
                . "Position:    {$vacancyName}\n"
                . "Candidate:   {$candidateName}\n"
                . "Interview:   {$interview->title}\n"
                . "Type:        " . ucfirst($interview->interview_type) . "\n"
                . "Date & Time: {$dateStr}\n"
                . "Duration:    {$dur} minutes\n"
                . ($interview->location ? "Location:    {$interview->location}\n" : "")
                . ($interview->agenda ? "\nAgenda:\n{$interview->agenda}\n" : "")
                . "\nPlease review the candidate's profile before the interview."
                . "\n\nRegards,\nHR Team";

            try {
                Mail::raw($body, function ($msg) use ($email, $vacancyName, $candidateName) {
                    $msg->to($email)
                        ->subject("Interview Invitation – {$vacancyName} – {$candidateName}");
                });

                $panelist->update([
                    'invite_sent'    => true,
                    'invite_sent_at' => now(),
                ]);
                $sent++;
            } catch (\Exception $e) {
                $errors[] = "{$email}: " . $e->getMessage();
            }
        }

        $interview->load(['panelists.employee']);
        return response()->json([
            'message'   => "{$sent} invite(s) sent.",
            'errors'    => $errors,
            'interview' => $this->fmtInterview($interview),
        ]);
    }

    // ── Save / update a panelist's scorecard ─────────────────────────────────

    public function saveScore(Request $request, Interview $interview): JsonResponse
    {
        abort_if($interview->organization_id !== $this->orgId(), 403);

        $v = $request->validate([
            'panelist_id'             => 'required|integer|exists:employees,id',
            'personal_presentation'   => 'nullable|numeric|min:0|max:10',
            'training_certifications' => 'nullable|numeric|min:0|max:10',
            'role_knowledge'          => 'nullable|numeric|min:0|max:10',
            'problem_solving'         => 'nullable|numeric|min:0|max:10',
            'teamskills_leadership'   => 'nullable|numeric|min:0|max:10',
            'attitude_adaptability'   => 'nullable|numeric|min:0|max:10',
            'personal_values'         => 'nullable|numeric|min:0|max:10',
            'interests_goals'         => 'nullable|numeric|min:0|max:10',
            'strengths'               => 'nullable|string|max:3000',
            'weaknesses'              => 'nullable|string|max:3000',
            'remarks'                 => 'nullable|string|max:3000',
            'recommendation'          => 'nullable|in:hire,hold,reject',
            'submit'                  => 'nullable|boolean',
        ]);

        $score = InterviewScore::firstOrNew([
            'interview_id' => $interview->id,
            'panelist_id'  => $v['panelist_id'],
        ]);

        $criteria = array_keys(InterviewScore::$criteria);
        foreach ($criteria as $key) {
            if (array_key_exists($key, $v)) {
                $score->{$key} = $v[$key];
            }
        }

        $score->strengths       = $v['strengths'] ?? $score->strengths;
        $score->weaknesses      = $v['weaknesses'] ?? $score->weaknesses;
        $score->remarks         = $v['remarks'] ?? $score->remarks;
        $score->recommendation  = $v['recommendation'] ?? $score->recommendation;

        $score->computeTotals();

        if (! empty($v['submit'])) {
            $score->submitted    = true;
            $score->submitted_at = now();
        }

        $score->save();

        // Recompute overall interview score from all submitted scores
        $allScores = InterviewScore::where('interview_id', $interview->id)
            ->where('submitted', true)
            ->whereNotNull('average_score')
            ->pluck('average_score');

        if ($allScores->isNotEmpty()) {
            $interview->update([
                'overall_score' => round($allScores->avg(), 2),
            ]);
        }

        $score->load('panelist');
        return response()->json($this->fmtScore($score));
    }

    // ── List employees available as panelists ─────────────────────────────────

    public function employees(Request $request): JsonResponse
    {
        $orgId = $this->orgId();
        $search = $request->query('q', '');

        $query = Employee::where('primary_organization_id', $orgId)
            ->where('employment_status', 'active')
            ->select('id', 'first_name', 'last_name', 'work_email', 'designation_id')
            ->with('designation:id,title');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                  ->orWhere('last_name', 'ilike', "%{$search}%")
                  ->orWhere('work_email', 'ilike', "%{$search}%");
            });
        }

        $employees = $query->orderBy('first_name')->limit(50)->get();

        return response()->json([
            'data' => $employees->map(fn($e) => [
                'id'          => $e->id,
                'name'        => trim("{$e->first_name} {$e->last_name}"),
                'work_email'  => $e->work_email,
                'designation' => $e->designation?->title,
            ])->values(),
        ]);
    }
}
