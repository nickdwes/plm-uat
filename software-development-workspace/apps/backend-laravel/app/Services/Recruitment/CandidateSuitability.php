<?php

namespace App\Services\Recruitment;

use App\Models\Recruitment\Application;
use App\Models\Recruitment\InterviewScore;

/**
 * How well somebody fits the job they applied for.
 *
 * Two halves, from two different kinds of evidence, and they are not
 * interchangeable. The skills half is what the applicant said about themselves
 * on the form; the panel half is what people who met them concluded. One is a
 * claim and one is a judgement, and a figure that blends them without saying
 * so is a figure nobody can argue with — which is the problem, not the point.
 *
 * ── The rule that matters most ──
 *
 * A candidate nobody has interviewed does not have a low suitability. They
 * have no suitability yet. Treating an absent panel score as a zero would rank
 * the untouched shortlist below the one bad interview, and the first person to
 * sort by this column would work down the list in exactly the wrong order.
 * So `overall` is null until both halves exist, and each half reports how much
 * it is standing on.
 *
 * Every number here carries what it was computed from: which skills matched,
 * which are missing, and how many panelists have actually submitted. A
 * recruiter defending a shortlist needs the working, not the score.
 */
class CandidateSuitability
{
    /**
     * What the interview is worth against what the form claimed.
     *
     * Weighted towards the panel because a conversation with somebody who has
     * done the job is better evidence than a list of boxes the applicant
     * ticked about themselves. Not weighted so far that it rescues a candidate
     * missing most of what the role asked for — which is why the skills half
     * keeps a substantial share rather than a token one.
     */
    private const SKILLS_WEIGHT = 0.4;
    private const PANEL_WEIGHT  = 0.6;

    /** Panel criteria are scored one to five. */
    private const PANEL_MAX = 5;

    public function for(Application $application): array
    {
        $skills = $this->skills($application);
        $panel  = $this->panel($application);

        return [
            'skills'  => $skills,
            'panel'   => $panel,
            'overall' => $this->overall($skills, $panel),
            'weights' => [
                'skills' => self::SKILLS_WEIGHT,
                'panel'  => self::PANEL_WEIGHT,
                'note'   => 'The panel counts for more because a conversation is better '
                    . 'evidence than a self-assessment, and the skills still count because '
                    . 'a good interview should not cover for missing half the requirement.',
            ],
        ];
    }

    // ── What they said they can do ────────────────────────────────────────────

    /**
     * The skills the role asked for, against the ones the applicant claimed.
     *
     * Matched case- and space-insensitively: "UI/UX Design" and "ui/ux design"
     * are the same skill typed by two people, and treating them as different
     * would quietly mark a qualified applicant down.
     */
    private function skills(Application $application): array
    {
        $required = $this->normalise($application->vacancy?->required_skills ?? []);

        if ($required === []) {
            return [
                'score'    => null,
                'required' => [],
                'matched'  => [],
                'missing'  => [],
                'note'     => 'This vacancy does not state the skills it needs, so there is '
                    . 'nothing to measure an applicant against.',
            ];
        }

        $claimed = $this->normalise($this->claimedSkills($application));

        $matched = array_values(array_intersect_key($required, $claimed));
        $missing = array_values(array_diff_key($required, $claimed));

        return [
            'score'    => round(count($matched) / count($required) * 100, 1),
            'required' => array_values($required),
            'matched'  => $matched,
            'missing'  => $missing,
            'note'     => $claimed === []
                ? 'The application form does not ask which of these the applicant has, so '
                    . 'none could be matched.'
                : null,
        ];
    }

    /**
     * Whatever the applicant ticked, from the field that offered them the list.
     *
     * Found by the field's declared type rather than by a fixed key, because
     * the form is built per vacancy and nothing guarantees what it is called.
     */
    private function claimedSkills(Application $application): array
    {
        $schema = $application->vacancy?->application_form_schema ?? [];
        $data   = $application->form_data ?? [];

        $claimed = [];

        foreach ($schema as $field) {
            if (($field['type'] ?? null) !== 'skills') {
                continue;
            }

            $value = $data[$field['key'] ?? ''] ?? null;

            if (is_array($value)) {
                $claimed = array_merge($claimed, $value);
            }
        }

        return $claimed;
    }

    // ── What the people who met them concluded ────────────────────────────────

    /**
     * The panel's verdict, averaged across everybody who has submitted one.
     *
     * Drafts are ignored. A half-filled scorecard sitting open on somebody's
     * screen is not an opinion yet, and averaging it in moves the number every
     * time they tab away.
     */
    private function panel(Application $application): array
    {
        $interviewIds = $application->interviews()->pluck('id');

        if ($interviewIds->isEmpty()) {
            return $this->noPanel('Nobody has interviewed this candidate yet.');
        }

        $scores = InterviewScore::whereIn('interview_id', $interviewIds)
            ->where('submitted', true)
            ->whereNotNull('average_score')
            ->get(['panelist_id', 'average_score', 'recommendation']);

        if ($scores->isEmpty()) {
            return $this->noPanel('The interview has happened but no panelist has submitted a score.');
        }

        $average = $scores->avg('average_score');

        return [
            'score'       => round($average / self::PANEL_MAX * 100, 1),
            'average'     => round($average, 2),
            'out_of'      => self::PANEL_MAX,
            'panelists'   => $scores->count(),
            /*
             * One panelist is one opinion. Said out loud because a single
             * enthusiastic scorecard reads identically to a unanimous panel
             * once it has been turned into a percentage.
             */
            'note'        => $scores->count() === 1
                ? 'One panelist has scored this candidate, so this is one person\'s view.'
                : null,
            'recommendations' => $scores->pluck('recommendation')->filter()->countBy()->all(),
        ];
    }

    private function noPanel(string $why): array
    {
        return ['score' => null, 'panelists' => 0, 'note' => $why, 'recommendations' => []];
    }

    // ── Putting the two together ──────────────────────────────────────────────

    /**
     * One figure, or an honest refusal to give one.
     *
     * Null while either half is missing. A shortlist sorted by a suitability
     * that counted "not yet interviewed" as nought would put the people nobody
     * has got to below the one person who interviewed badly.
     */
    private function overall(array $skills, array $panel): array
    {
        if ($skills['score'] === null || $panel['score'] === null) {
            return [
                'score' => null,
                'why'   => $panel['score'] === null
                    ? $panel['note']
                    : $skills['note'] ?? 'The role states no required skills.',
            ];
        }

        return [
            'score' => round(
                $skills['score'] * self::SKILLS_WEIGHT + $panel['score'] * self::PANEL_WEIGHT,
                1,
            ),
            'why'   => null,
        ];
    }

    /** @return array<string, string> keyed by a comparable form of each skill */
    private function normalise(array $skills): array
    {
        $out = [];

        foreach ($skills as $skill) {
            $skill = trim((string) $skill);

            if ($skill === '') {
                continue;
            }

            $key = mb_strtolower(preg_replace('/\s+/', ' ', $skill) ?? $skill);

            // First spelling wins, so the role's own wording is what is shown.
            $out[$key] ??= $skill;
        }

        return $out;
    }
}
