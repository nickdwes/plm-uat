<?php

namespace App\Models\Recruitment;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterviewScore extends Model
{
    protected $table = 'interview_scores';

    protected $fillable = [
        'interview_id',
        'panelist_id',
        'personal_presentation',
        'training_certifications',
        'role_knowledge',
        'problem_solving',
        'teamskills_leadership',
        'attitude_adaptability',
        'personal_values',
        'interests_goals',
        'total',
        'average_score',
        'strengths',
        'weaknesses',
        'remarks',
        'recommendation',
        'submitted',
        'submitted_at',
        'criterion_comments',
        'technical_exercise_conducted',
        'technical_exercise_score',
        'technical_exercise_notes',
    ];

    protected $casts = [
        'personal_presentation'   => 'float',
        'training_certifications' => 'float',
        'role_knowledge'          => 'float',
        'problem_solving'         => 'float',
        'teamskills_leadership'   => 'float',
        'attitude_adaptability'   => 'float',
        'personal_values'         => 'float',
        'interests_goals'         => 'float',
        'total'                   => 'float',
        'average_score'           => 'float',
        'submitted'               => 'boolean',
        'submitted_at'            => 'datetime',
        'criterion_comments'      => 'array',
        'technical_exercise_conducted' => 'boolean',
        'technical_exercise_score'     => 'float',
    ];

    /** Score criteria and their display labels */
    public static array $criteria = [
        'personal_presentation'   => 'Personal Presentation & Communication',
        'training_certifications' => 'Training & Certifications',
        'role_knowledge'          => 'Role Knowledge, Skills & Experience',
        'problem_solving'         => 'Creative/Analytical/Problem Solving & Resourcefulness',
        'teamskills_leadership'   => 'Teamskills & Leadership',
        'attitude_adaptability'   => 'Attitude & Adaptability',
        'personal_values'         => 'Personal/Professional Values',
        'interests_goals'         => 'Interests & Goals',
    ];

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class, 'interview_id');
    }

    public function panelist(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'panelist_id');
    }

    /** Compute and set total + average from criteria scores */
    public function computeTotals(): void
    {
        $scores = [];
        foreach (array_keys(self::$criteria) as $key) {
            if ($this->{$key} !== null) {
                $scores[] = (float) $this->{$key};
            }
        }

        if (empty($scores)) {
            $this->total = null;
            $this->average_score = null;
        } else {
            $this->total = array_sum($scores);
            $this->average_score = round(array_sum($scores) / count($scores), 2);
        }
    }
}
