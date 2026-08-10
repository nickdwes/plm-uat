<?php

namespace App\Http\Requests\Recruitment;

use App\Models\Recruitment\Application;
use Illuminate\Foundation\Http\FormRequest;

class MoveApplicationStageRequest extends FormRequest
{
    /**
     * Deferred to the policy, which already has a `moveStage` ability for
     * exactly this and was never asked.
     *
     * Authorised against the application being moved rather than the class:
     * advancing somebody to offer, or moving them to rejected, is a decision
     * about that person, and "manages anybody" was the only thing standing in
     * front of it.
     */
    public function authorize(): bool
    {
        $application = Application::withoutGlobalScopes()
            ->find($this->route('application'));

        if (! $application) {
            return false;
        }

        return $this->user()?->can('moveStage', $application) ?? false;
    }

    public function rules(): array
    {
        return [
            'stage_id' => 'required|integer|exists:workflow_stages,id',
            'notes'    => 'nullable|string|max:1000',
        ];
    }
}
