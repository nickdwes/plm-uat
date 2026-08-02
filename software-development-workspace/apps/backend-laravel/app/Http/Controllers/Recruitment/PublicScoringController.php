<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\Recruitment\Interview;
use App\Models\Recruitment\InterviewPanelist;
use App\Models\Recruitment\InterviewScore;
use App\Models\Recruitment\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public (unauthenticated) scoring form endpoint.
 * Access is controlled by a time-limited token stored on InterviewPanelist.
 */
class PublicScoringController extends Controller
{
    /** Scoring criteria exactly matching the spreadsheet columns */
    private const CRITERIA = [
        'personal_presentation'   => 'General Appearance, Presentation & Communication',
        'training_certifications' => 'Academic (Training) Qualification',
        'role_knowledge'          => 'Role Skills, Knowledge & Experience',
        'problem_solving'         => 'Creative/Analytical/Problem Solving Skills',
        'teamskills_leadership'   => 'Interpersonal Skills / Teamwork',
        'attitude_adaptability'   => 'Attitude & Adaptability',
        'personal_values'         => 'Personal/Professional Values',
        'interests_goals'         => 'Interests & Goals',
    ];

    // ── Resolve and validate token ─────────────────────────────────────────

    private function resolveToken(string $token): ?InterviewPanelist
    {
        return InterviewPanelist::where('scoring_token', $token)
            ->where(function ($q) {
                $q->whereNull('scoring_token_expires_at')
                  ->orWhere('scoring_token_expires_at', '>', now());
            })
            ->with(['interview.application.candidate', 'interview.application.vacancy', 'employee'])
            ->first();
    }

    // ── GET /public/scoring/{token} ────────────────────────────────────────

    public function show(string $token): JsonResponse
    {
        $panelist = $this->resolveToken($token);

        if (! $panelist) {
            return response()->json(['message' => 'This scoring link is invalid or has expired.'], 404);
        }

        $interview   = $panelist->interview;
        $application = $interview->application;
        $candidate   = $application?->candidate;
        $vacancy     = $application?->vacancy;

        // Existing score (draft or submitted)
        $existing = InterviewScore::where('interview_id', $interview->id)
            ->where('panelist_id', $panelist->employee_id)
            ->first();

        return response()->json([
            'panelist' => [
                'id'   => $panelist->id,
                'name' => $panelist->employee ? trim("{$panelist->employee->first_name} {$panelist->employee->last_name}") : 'Panelist',
                'role' => $panelist->role,
            ],
            'interview' => [
                'id'               => $interview->id,
                'title'            => $interview->title,
                'interview_type'   => $interview->interview_type,
                'scheduled_at'     => $interview->scheduled_at?->toIso8601String(),
                'duration_minutes' => $interview->duration_minutes,
                'location'         => $interview->location,
                'agenda'           => $interview->agenda,
                'teams_join_url'   => $interview->teams_join_url,
            ],
            'candidate' => [
                'name'       => $candidate ? trim("{$candidate->first_name} {$candidate->last_name}") : 'Candidate',
                'resume_url' => $candidate?->signedResumeUrl(240),
            ],
            'vacancy' => [
                'name' => $vacancy?->name ?? 'Role',
            ],
            'criteria' => self::CRITERIA,
            'existing_score' => $existing ? [
                'personal_presentation'          => $existing->personal_presentation,
                'training_certifications'        => $existing->training_certifications,
                'role_knowledge'                 => $existing->role_knowledge,
                'problem_solving'                => $existing->problem_solving,
                'teamskills_leadership'          => $existing->teamskills_leadership,
                'attitude_adaptability'          => $existing->attitude_adaptability,
                'personal_values'                => $existing->personal_values,
                'interests_goals'                => $existing->interests_goals,
                'criterion_comments'             => $existing->criterion_comments ?? [],
                'technical_exercise_conducted'   => $existing->technical_exercise_conducted,
                'technical_exercise_score'       => $existing->technical_exercise_score,
                'technical_exercise_notes'       => $existing->technical_exercise_notes,
                'remarks'                        => $existing->remarks,
                'recommendation'                 => $existing->recommendation,
                'total'                          => $existing->total,
                'average_score'                  => $existing->average_score,
                'submitted'                      => $existing->submitted,
            ] : null,
        ]);
    }

    // ── POST /public/scoring/{token} ───────────────────────────────────────

    public function submit(string $token, Request $request): JsonResponse
    {
        $panelist = $this->resolveToken($token);

        if (! $panelist) {
            return response()->json(['message' => 'This scoring link is invalid or has expired.'], 404);
        }

        $interview = $panelist->interview;

        // Block re-submission of already-submitted scores
        $existing = InterviewScore::where('interview_id', $interview->id)
            ->where('panelist_id', $panelist->employee_id)
            ->first();

        if ($existing?->submitted && ! $request->boolean('override')) {
            return response()->json(['message' => 'You have already submitted your scores for this interview.'], 422);
        }

        $isDraft = $request->boolean('draft');

        $v = $request->validate([
            'personal_presentation'        => ($isDraft ? 'nullable' : 'required') . '|numeric|min:1|max:5',
            'training_certifications'      => ($isDraft ? 'nullable' : 'required') . '|numeric|min:1|max:5',
            'role_knowledge'               => ($isDraft ? 'nullable' : 'required') . '|numeric|min:1|max:5',
            'problem_solving'              => ($isDraft ? 'nullable' : 'required') . '|numeric|min:1|max:5',
            'teamskills_leadership'        => ($isDraft ? 'nullable' : 'required') . '|numeric|min:1|max:5',
            'attitude_adaptability'        => ($isDraft ? 'nullable' : 'required') . '|numeric|min:1|max:5',
            'personal_values'              => ($isDraft ? 'nullable' : 'required') . '|numeric|min:1|max:5',
            'interests_goals'              => ($isDraft ? 'nullable' : 'required') . '|numeric|min:1|max:5',
            'criterion_comments'           => 'nullable|array',
            'criterion_comments.*'         => 'nullable|string|max:1000',
            'technical_exercise_conducted' => 'nullable|boolean',
            'technical_exercise_score'     => 'nullable|numeric|min:0|max:100',
            'technical_exercise_notes'     => 'nullable|string|max:3000',
            'remarks'                      => 'nullable|string|max:3000',
            'recommendation'               => 'nullable|in:hire,hold,reject',
            'draft'                        => 'nullable|boolean',
        ]);

        $score = $existing ?? new InterviewScore([
            'interview_id' => $interview->id,
            'panelist_id'  => $panelist->employee_id,
        ]);

        foreach (array_keys(self::CRITERIA) as $key) {
            $score->{$key} = $v[$key];
        }

        $score->remarks        = $v['remarks'] ?? $score->remarks;
        $score->recommendation = $v['recommendation'] ?? $score->recommendation;

        // Per-criterion comments
        if (array_key_exists('criterion_comments', $v)) {
            $score->criterion_comments = $v['criterion_comments'] ?: null;
        }

        // Technical exercise
        if (array_key_exists('technical_exercise_conducted', $v)) {
            $score->technical_exercise_conducted = (bool) ($v['technical_exercise_conducted'] ?? false);
        }
        if (array_key_exists('technical_exercise_score', $v)) {
            $score->technical_exercise_score = $v['technical_exercise_score'];
        }
        if (array_key_exists('technical_exercise_notes', $v)) {
            $score->technical_exercise_notes = $v['technical_exercise_notes'] ?: null;
        }

        $score->computeTotals();

        if (! $isDraft) {
            $score->submitted    = true;
            $score->submitted_at = now();
        }

        $score->save();

        // Recompute overall interview score
        $allScores = InterviewScore::where('interview_id', $interview->id)
            ->where('submitted', true)
            ->whereNotNull('average_score')
            ->pluck('average_score');

        if ($allScores->isNotEmpty()) {
            $interview->update(['overall_score' => round($allScores->avg(), 2)]);
        }

        return response()->json([
            'message'       => $score->submitted ? 'Scores submitted successfully.' : 'Draft saved.',
            'total'         => $score->total,
            'average_score' => $score->average_score,
            'submitted'     => $score->submitted,
        ]);
    }
}
