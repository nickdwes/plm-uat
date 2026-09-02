<?php

namespace App\Http\Requests\Training;

use App\Models\Training\TrainingBudgetRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTrainingBudgetRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', TrainingBudgetRequest::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'mode'                 => 'required|in:by_training,by_employee',
            'training_item_id'     => [Rule::requiredIf(fn () => $this->input('mode') === 'by_training'), 'nullable', 'integer', 'exists:training_items,id'],
            'employee_id'          => [Rule::requiredIf(fn () => $this->input('mode') === 'by_employee'), 'nullable', 'integer', 'exists:employees,id'],
            'payment_instructions' => 'required|string|max:2000',
            'currency'             => 'required|string|size:3',

            'items'                     => 'required|array|min:1',
            'items.*.employee_id'       => 'required|integer|exists:employees,id',
            'items.*.training_item_id'  => 'required|integer|exists:training_items,id',
            'items.*.cost'              => 'required|numeric|min:0',
            'items.*.achieve_by_date'   => 'nullable|date',
        ];
    }

    /**
     * The per-line consistency rules a plain `rules()` array can't express:
     * every line in "one course, several people" mode really does name that
     * course, every line in "one person, several courses" mode really does
     * name that person, and nobody appears twice on the same request.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $mode  = $this->input('mode');
            $items = $this->input('items', []);
            if (!is_array($items)) {
                return;
            }

            if ($mode === 'by_training') {
                $trainingId = (int) $this->input('training_item_id');
                foreach ($items as $i => $item) {
                    if ((int) ($item['training_item_id'] ?? 0) !== $trainingId) {
                        $validator->errors()->add("items.$i.training_item_id", 'Every line must use the training selected above.');
                    }
                }
                $employeeIds = array_column($items, 'employee_id');
                if (count($employeeIds) !== count(array_unique($employeeIds))) {
                    $validator->errors()->add('items', 'Each employee can only appear once.');
                }
            } elseif ($mode === 'by_employee') {
                $employeeId = (int) $this->input('employee_id');
                foreach ($items as $i => $item) {
                    if ((int) ($item['employee_id'] ?? 0) !== $employeeId) {
                        $validator->errors()->add("items.$i.employee_id", 'Every line must use the employee selected above.');
                    }
                }
                $trainingIds = array_column($items, 'training_item_id');
                if (count($trainingIds) !== count(array_unique($trainingIds))) {
                    $validator->errors()->add('items', 'Each training can only appear once.');
                }
            }
        });
    }
}
