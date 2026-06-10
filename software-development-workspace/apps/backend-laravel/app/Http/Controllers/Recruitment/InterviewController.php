<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Interview;
use App\Models\Recruitment\InterviewPanelist;
use App\Models\Recruitment\InterviewScore;
use App\Services\Recruitment\TeamsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

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
            'id'              => $p->id,
            'employee_id'     => $p->employee_id,
            'name'            => $p->employee
                                    ? trim("{$p->employee->first_name} {$p->employee->last_name}")
                                    : ($p->external_name ?? $p->external_email ?? 'External'),
            'work_email'      => $p->employee?->work_email ?? $p->external_email,
            'external_email'  => $p->external_email,
            'is_external'     => is_null($p->employee_id),
            'role'            => $p->role,
            'invite_sent'     => $p->invite_sent,
            'invite_sent_at'  => $p->invite_sent_at?->toIso8601String(),
            'confirmed'       => $p->confirmed,
            'scoring_url'     => $p->scoring_token ? TeamsService::scoringUrl($p->scoring_token) : null,
            'score_submitted' => InterviewScore::where('interview_id', $i->id)->where('panelist_id', $p->employee_id)->where('submitted', true)->exists(),
        ]);

        $data = [
            'id'                    => $i->id,
            'application_id'        => $i->application_id,
            'vacancy_id'            => $i->vacancy_id,
            'title'                 => $i->title,
            'interview_type'        => $i->interview_type,
            'scheduled_at'          => $i->scheduled_at?->toIso8601String(),
            'duration_minutes'      => $i->duration_minutes,
            'location'              => $i->location,
            'agenda'                => $i->agenda,
            'status'                => $i->status,
            'overall_notes'         => $i->overall_notes,
            'overall_score'         => $i->overall_score,
            'recommendation'        => $i->recommendation,
            'teams_join_url'           => $i->teams_join_url,
            'candidate_invite_sent'    => $i->candidate_invite_sent,
            'candidate_invite_sent_at' => $i->candidate_invite_sent_at?->toIso8601String(),
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
            'criterion_comments'             => $s->criterion_comments ?? [],
            'technical_exercise_conducted'   => $s->technical_exercise_conducted,
            'technical_exercise_score'       => $s->technical_exercise_score,
            'technical_exercise_notes'       => $s->technical_exercise_notes,
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
            'title'              => 'required|string|max:200',
            'interview_type'     => 'required|in:phone,video,onsite,panel,technical,hr',
            'scheduled_at'       => 'required|date',
            'duration_minutes'   => 'required|integer|min:15|max:480',
            'location'           => 'nullable|string|max:300',
            'agenda'             => 'nullable|string|max:5000',
            'panelist_ids'       => 'nullable|array',
            'panelist_ids.*'     => 'integer|exists:employees,id',
            'panelist_roles'     => 'nullable|array',
            'panelist_roles.*'   => 'in:lead,panelist,observer',
            'external_panelists' => 'nullable|array',
            'external_panelists.*.email' => 'required|email|max:255',
            'external_panelists.*.name'  => 'nullable|string|max:150',
            'external_panelists.*.role'  => 'nullable|in:lead,panelist,observer',
        ]);

        $empIds   = $v['panelist_ids'] ?? [];
        $extPanel = $v['external_panelists'] ?? [];

        if (empty($empIds) && empty($extPanel)) {
            return response()->json(['message' => 'At least one panelist is required.'], 422);
        }

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

        // Add employee panelists
        $roles = $v['panelist_roles'] ?? [];
        foreach ($empIds as $idx => $empId) {
            InterviewPanelist::create([
                'interview_id' => $interview->id,
                'employee_id'  => $empId,
                'role'         => $roles[$idx] ?? 'panelist',
            ]);
        }

        // Add external (email-only) panelists
        foreach ($extPanel as $ext) {
            InterviewPanelist::create([
                'interview_id'   => $interview->id,
                'employee_id'    => null,
                'external_email' => $ext['email'],
                'external_name'  => $ext['name'] ?? null,
                'role'           => $ext['role'] ?? 'panelist',
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

    // ── Send panelist invites (with scoring link + CV attachment) ─────────────

    public function sendInvites(Request $request, Interview $interview): JsonResponse
    {
        abort_if($interview->organization_id !== $this->orgId(), 403);

        $interview->load(['panelists.employee', 'scores']);
        $application   = Application::with('candidate', 'vacancy')->find($interview->application_id);
        $candidateName = $application?->candidate
            ? trim("{$application->candidate->first_name} {$application->candidate->last_name}")
            : 'Candidate';
        $vacancyName  = $application?->vacancy?->name ?? 'the role';
        $resumeUrl    = $application?->candidate?->resume_path
            ? Storage::url($application->candidate->resume_path)
            : null;

        $sent   = 0;
        $errors = [];

        foreach ($interview->panelists as $panelist) {
            $email = $panelist->employee?->work_email ?? $panelist->external_email;
            if (! $email) continue;

            // Issue / refresh scoring token
            $scoringToken = TeamsService::issueScoringToken($panelist);
            $scoringLink  = TeamsService::scoringUrl($scoringToken);

            $name = $panelist->employee
                ? trim("{$panelist->employee->first_name} {$panelist->employee->last_name}")
                : ($panelist->external_name ?? $email);
            $dateStr = $interview->scheduled_at->format('l, d F Y \a\t H:i');

            $html = "<p>Dear <strong>{$name}</strong>,</p>"
                . "<p>You have been invited as an interview panelist (<em>{$panelist->role}</em>) for:</p>"
                . "<table style='border-collapse:collapse;font-size:14px'>"
                . "<tr><td style='padding:4px 16px 4px 0;font-weight:600'>Position</td><td>{$vacancyName}</td></tr>"
                . "<tr><td style='padding:4px 16px 4px 0;font-weight:600'>Candidate</td><td>{$candidateName}</td></tr>"
                . "<tr><td style='padding:4px 16px 4px 0;font-weight:600'>Interview</td><td>{$interview->title}</td></tr>"
                . "<tr><td style='padding:4px 16px 4px 0;font-weight:600'>Type</td><td>" . ucfirst($interview->interview_type) . "</td></tr>"
                . "<tr><td style='padding:4px 16px 4px 0;font-weight:600'>Date &amp; Time</td><td>{$dateStr}</td></tr>"
                . "<tr><td style='padding:4px 16px 4px 0;font-weight:600'>Duration</td><td>{$interview->duration_minutes} minutes</td></tr>"
                . ($interview->location ? "<tr><td style='padding:4px 16px 4px 0;font-weight:600'>Location</td><td>{$interview->location}</td></tr>" : "")
                . ($interview->teams_join_url ? "<tr><td style='padding:4px 16px 4px 0;font-weight:600'>Teams Link</td><td><a href='{$interview->teams_join_url}'>{$interview->teams_join_url}</a></td></tr>" : "")
                . "</table>"
                . ($interview->agenda ? "<br><strong>Agenda:</strong><br>" . nl2br(htmlspecialchars($interview->agenda)) : "")
                . "<br><br>"
                . "<div style='background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:16px;margin:16px 0'>"
                . "<p style='margin:0 0 8px;font-weight:600;color:#0369a1'>&#9997; Interview Scoring Form</p>"
                . "<p style='margin:0 0 12px;color:#374151'>Please complete your scoring form after the interview. This link is personal to you — do not share it.</p>"
                . "<a href='{$scoringLink}' style='background:#004C97;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;display:inline-block'>Open Scoring Form</a>"
                . "</div>"
                . ($resumeUrl
                    ? "<p><strong>Candidate CV:</strong> <a href='{$resumeUrl}'>Download Resume</a> (please review before the interview)</p>"
                    : "<p><em>No CV on file for this candidate.</em></p>")
                . "<br>Regards,<br>HR Team";

            try {
                Mail::html($html, function ($msg) use ($email, $name, $vacancyName, $candidateName) {
                    $msg->to($email, $name)
                        ->subject("Interview Panel Invitation – {$vacancyName} – {$candidateName}");
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
            'message'   => "{$sent} panelist invite(s) sent.",
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
            'personal_presentation'   => 'nullable|numeric|min:1|max:5',
            'training_certifications' => 'nullable|numeric|min:1|max:5',
            'role_knowledge'          => 'nullable|numeric|min:1|max:5',
            'problem_solving'         => 'nullable|numeric|min:1|max:5',
            'teamskills_leadership'   => 'nullable|numeric|min:1|max:5',
            'attitude_adaptability'   => 'nullable|numeric|min:1|max:5',
            'personal_values'         => 'nullable|numeric|min:1|max:5',
            'interests_goals'         => 'nullable|numeric|min:1|max:5',
            'criterion_comments'      => 'nullable|array',
            'criterion_comments.*'    => 'nullable|string|max:1000',
            'technical_exercise_conducted' => 'nullable|boolean',
            'technical_exercise_score'     => 'nullable|numeric|min:0|max:100',
            'technical_exercise_notes'     => 'nullable|string|max:3000',
            'remarks'                 => 'nullable|string|max:3000',
            'recommendation'          => 'nullable|in:hire,hold,reject',
            'submit'                  => 'nullable|boolean',
        ]);

        $score = InterviewScore::firstOrNew([
            'interview_id' => $interview->id,
            'panelist_id'  => $v['panelist_id'],
        ]);

        $fields = ['personal_presentation','training_certifications','role_knowledge','problem_solving',
                   'teamskills_leadership','attitude_adaptability','personal_values','interests_goals'];
        foreach ($fields as $f) {
            if (array_key_exists($f, $v)) $score->$f = $v[$f];
        }
        foreach (['remarks','recommendation'] as $f) {
            if (array_key_exists($f, $v)) $score->$f = $v[$f];
        }
        if (array_key_exists('criterion_comments', $v)) {
            $score->criterion_comments = $v['criterion_comments'] ?: null;
        }
        if (array_key_exists('technical_exercise_conducted', $v)) {
            $score->technical_exercise_conducted = (bool) ($v['technical_exercise_conducted'] ?? false);
        }
        if (array_key_exists('technical_exercise_score', $v)) {
            $score->technical_exercise_score = $v['technical_exercise_score'];
        }
        if (array_key_exists('technical_exercise_notes', $v)) {
            $score->technical_exercise_notes = $v['technical_exercise_notes'] ?: null;
        }
        if (!empty($v['submit'])) {
            $score->submitted    = true;
            $score->submitted_at = now();
        }
        $score->save();
        $score->computeTotals();
        $score->save();

        // Recompute overall interview score from all submitted panelist scores
        $allScores = InterviewScore::where('interview_id', $interview->id)
            ->where('submitted', true)
            ->whereNotNull('average_score')
            ->pluck('average_score');
        if ($allScores->isNotEmpty()) {
            $interview->update(['overall_score' => round($allScores->avg(), 2)]);
        }

        $interview->load(['panelists.employee', 'scores.panelist']);
        return response()->json(['score' => $this->fmtScore($score), 'interview' => $this->fmtInterview($interview, true)]);
    }

    // ── Create Teams calendar event ───────────────────────────────────────────

    public function createTeamsEvent(Request $request, Interview $interview): JsonResponse
    {
        abort_if($interview->organization_id !== $this->orgId(), 403);

        $interview->load(['panelists.employee', 'application.candidate', 'application.vacancy']);
        $application   = $interview->application;
        $candidateName = $application?->candidate ? trim("{$application->candidate->first_name} {$application->candidate->last_name}") : 'Candidate';
        $vacancyName   = $application?->vacancy?->name ?? 'the role';

        $attendees = [];
        foreach ($interview->panelists as $p) {
            $e = $p->employee?->work_email ?? $p->external_email;
            if ($e) $attendees[] = $e;
        }
        $attendees = array_values(array_unique(array_filter($attendees)));

        $subject  = "Interview: {$interview->title} – {$candidateName} – {$vacancyName}";
        $bodyHtml = "<p>You are invited to an interview.</p>"
            . "<p><strong>Candidate:</strong> {$candidateName}<br>"
            . "<strong>Position:</strong> {$vacancyName}<br>"
            . "<strong>Type:</strong> " . ucfirst($interview->interview_type) . "<br>"
            . "<strong>Duration:</strong> {$interview->duration_minutes} minutes</p>"
            . ($interview->agenda ? "<p><strong>Agenda:</strong><br>" . nl2br(htmlspecialchars($interview->agenda)) . "</p>" : "");

        try {
            $teams  = new TeamsService();
            $result = $teams->createOrUpdateEvent($interview, $attendees, $subject, $bodyHtml);

            $interview->update([
                'teams_event_id' => $result['event_id'],
                'teams_join_url' => $result['join_url'],
            ]);

            $interview->load(['panelists.employee', 'scores.panelist']);
            return response()->json([
                'message'        => 'Teams event created successfully.',
                'teams_join_url' => $result['join_url'],
                'interview'      => $this->fmtInterview($interview, true),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    // ── Send candidate schedule email ─────────────────────────────────────────

    public function sendCandidateInvite(Request $request, Interview $interview): JsonResponse
    {
        abort_if($interview->organization_id !== $this->orgId(), 403);

        $interview->load(['application.candidate', 'application.vacancy']);
        $application = $interview->application;
        $candidate   = $application?->candidate;

        if (! $candidate?->email) {
            return response()->json(['message' => 'No candidate email address found.'], 422);
        }

        $candidateName = trim("{$candidate->first_name} {$candidate->last_name}");
        $vacancyName   = $application?->vacancy?->name ?? 'the role';
        $dateStr       = $interview->scheduled_at->format('l, d F Y \a\t H:i');

        $bodyLines = [
            "Dear {$candidateName},",
            "",
            "We are pleased to invite you to an interview for the position of <strong>{$vacancyName}</strong>.",
            "",
            "<table style='border-collapse:collapse;font-size:14px'>",
            "<tr><td style='padding:4px 16px 4px 0;font-weight:600'>Interview</td><td>{$interview->title}</td></tr>",
            "<tr><td style='padding:4px 16px 4px 0;font-weight:600'>Type</td><td>" . ucfirst($interview->interview_type) . "</td></tr>",
            "<tr><td style='padding:4px 16px 4px 0;font-weight:600'>Date &amp; Time</td><td>{$dateStr}</td></tr>",
            "<tr><td style='padding:4px 16px 4px 0;font-weight:600'>Duration</td><td>{$interview->duration_minutes} minutes</td></tr>",
        ];
        if ($interview->location)      $bodyLines[] = "<tr><td style='padding:4px 16px 4px 0;font-weight:600'>Location</td><td>{$interview->location}</td></tr>";
        if ($interview->teams_join_url) $bodyLines[] = "<tr><td style='padding:4px 16px 4px 0;font-weight:600'>Teams Link</td><td><a href='{$interview->teams_join_url}'>{$interview->teams_join_url}</a></td></tr>";
        $bodyLines[] = "</table>";
        if ($interview->agenda) $bodyLines[] = "<br><strong>Agenda:</strong><br>" . nl2br(htmlspecialchars($interview->agenda));
        $bodyLines[] = "<br>Please confirm your attendance by replying to this email.<br><br>Regards,<br>HR Team";
        $html = implode("\n", $bodyLines);

        try {
            Mail::html($html, function ($msg) use ($candidate, $vacancyName, $candidateName) {
                $msg->to($candidate->email, $candidateName)->subject("Interview Invitation – {$vacancyName}");
            });

            $interview->update(['candidate_invite_sent' => true, 'candidate_invite_sent_at' => now()]);
            $interview->load(['panelists.employee', 'scores.panelist']);
            return response()->json(['message' => 'Candidate interview invitation sent.', 'interview' => $this->fmtInterview($interview, true)]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to send email: ' . $e->getMessage()], 502);
        }
    }

    // ── Employee search ───────────────────────────────────────────────────────

    public function employees(Request $request): JsonResponse
    {
        $orgId  = $this->orgId();
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
