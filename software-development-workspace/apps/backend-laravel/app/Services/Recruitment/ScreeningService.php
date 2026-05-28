<?php

namespace App\Services\Recruitment;

use App\Models\Recruitment\Application;
use App\Models\Recruitment\Vacancy;

class ScreeningService
{
    /**
     * Evaluate an application against the vacancy's screening rules.
     * Returns a score 0-100, or null if no rules are defined.
     */
    public function evaluate(Application $application, Vacancy $vacancy): ?int
    {
        $rules = $vacancy->application_form_schema ? json_decode(json_encode($vacancy), true)['screening_rules'] ?? null : null;

        // Try loading from the vacancy model directly
        $screeningRules = null;
        if ($vacancy->template_id) {
            $template = $vacancy->template;
            $screeningRules = $template?->screening_rules;
        }

        if (empty($screeningRules)) {
            return null;
        }

        $score = 0;
        $maxScore = 0;
        $formData = $application->form_data ?? [];

        foreach ($screeningRules as $rule) {
            $weight = (int) ($rule['weight'] ?? 10);
            $maxScore += $weight;

            switch ($rule['type'] ?? '') {
                case 'keyword_match':
                    $keywords  = array_map('strtolower', (array) ($rule['keywords'] ?? []));
                    $text      = strtolower(implode(' ', $formData));
                    $candidate = $application->candidate;
                    $text     .= ' ' . strtolower(implode(' ', $candidate->keywords ?? []));
                    $matched   = count(array_filter($keywords, fn($k) => str_contains($text, $k)));
                    if ($matched > 0) {
                        $score += (int) ($weight * min($matched / count($keywords), 1.0));
                    }
                    break;

                case 'required_field':
                    $field = $rule['field'] ?? null;
                    if ($field && !empty($formData[$field])) {
                        $score += $weight;
                    }
                    break;
            }
        }

        if ($maxScore === 0) {
            return null;
        }

        return (int) round(($score / $maxScore) * 100);
    }
}
