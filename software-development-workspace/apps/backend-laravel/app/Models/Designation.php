<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Designation extends Model
{
    use HasFactory, BelongsToOrganization, \App\Models\Concerns\RecordsAuditTrail;

    protected $fillable = [
        'organization_id',
        'job_grade_id',
        'title',
        'description',
        'job_description',
        'desired_skills',
        'certifications',
    ];

    protected $casts = [
        'desired_skills' => 'array',
        'certifications' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Skills tidied the way CandidateSuitability compares them.
     *
     * Blank entries dropped and duplicates collapsed case-insensitively, so a
     * list holding "Azure", "azure " and "" counts once. Left unsorted: the
     * order somebody typed them in is the order of importance they meant.
     *
     * @param  array<int, string>|null  $value
     */
    public function setDesiredSkillsAttribute(?array $value): void
    {
        $this->attributes['desired_skills'] = $value === null ? null : json_encode(self::tidy($value));
    }

    /** @param  array<int, string>|null  $value */
    public function setCertificationsAttribute(?array $value): void
    {
        $this->attributes['certifications'] = $value === null ? null : json_encode(self::tidy($value));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private static function tidy(array $values): array
    {
        $seen = [];

        foreach ($values as $value) {
            $text = trim((string) $value);

            if ($text === '') {
                continue;
            }

            $seen[mb_strtolower($text)] ??= $text;
        }

        return array_values($seen);
    }
}
