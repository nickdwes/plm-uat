<?php

namespace App\Http\Requests\Recruitment;

use App\Models\Recruitment\Candidate;
use Illuminate\Foundation\Http\FormRequest;

class CreateCandidateRequest extends FormRequest
{
    /**
     * Deferred to the policy, so there is one answer rather than two.
     *
     * This asked whether the caller manages anybody, which is not the same
     * question as whether they may add a candidate: it let every team lead in
     * the company create one, and refused a recruiter whose role granted
     * exactly `candidates.create`.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Candidate::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'first_name'   => 'required|string|max:80',
            'middle_name'  => 'nullable|string|max:80',
            'last_name'    => 'required|string|max:80',
            'email'        => 'required|email|max:200',
            'phone'        => 'nullable|string|max:30',
            'social_links' => 'nullable|array',
            'keywords'     => 'nullable|array',
            'source'       => 'nullable|string|max:80',
            'notes'        => 'nullable|string',
        ];
    }
}
