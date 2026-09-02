<?php

namespace App\Http\Requests\Training;

use App\Models\Training\TrainingItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTrainingItemRequest extends FormRequest
{
    /**
     * Deferred to the policy, so there is one answer rather than two.
     *
     * This checked a role name, which meant a role granted `training.manage`
     * was refused here whatever the policy said, and anybody whose role happens
     * to be called HR was allowed regardless of what it grants.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', TrainingItem::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'title'            => 'required|string|max:200',
            'training_version' => 'nullable|string|max:50',
            'description'      => 'nullable|string',
            'type'             => 'required|in:course,workshop,certification,exam_voucher,subscription,on_job,mentoring,conference',
            'delivery'         => 'required|in:in_person,virtual,e_learning,blended,self_study',
            'level'            => 'nullable|in:beginner,foundation,associate,professional,expert,master',
            'duration_hours'   => 'required|integer|min:0',
            'duration_minutes' => 'integer|min:0|max:59',
            'provider_name'    => 'nullable|string|max:200',
            'provider_url'     => 'nullable|url|max:500',
            'cost_currency'    => 'nullable|string|size:3',
            'estimated_cost'   => 'nullable|numeric|min:0',
            'tags'             => 'nullable|array',
            'tags.*'           => 'string|max:50',
            'is_active'         => 'boolean',
            'is_featured'       => 'boolean',
            'requires_approval' => 'boolean',
            'is_budgeted'       => 'boolean',
            'budget_amount'     => 'nullable|numeric|min:0',
            'max_enrollments'   => 'nullable|integer|min:1',
            'thumbnail_url'     => 'nullable|url|max:500',
            'competency_ids'    => 'nullable|array',
            'competency_ids.*'  => 'integer|exists:training_competency_items,id',

            // Classification
            'is_technical'            => 'boolean',
            'requires_proctored_exam' => 'boolean',

            // Training Provider — who stands behind this training: either a
            // vendor whose product it covers, or a professional body that
            // certifies it. Never both; which one is required is enforced
            // in withValidator() below, since "required if the other field
            // says so" isn't expressible as a plain rule here.
            'training_provider_type' => 'required|in:certifying_body,vendor_oem',
            'certifying_body_id'     => ['nullable', 'integer', Rule::exists('training_certifying_bodies', 'id')->where('is_active', true)],
            'vendor_partner_id'      => ['nullable', 'integer', Rule::exists('vendor_partners', 'id')->where('is_oem_vendor', true)],
            'product_ids'            => 'nullable|array',
            'product_ids.*'          => [
                'integer',
                Rule::exists('vendor_products', 'id')->where('vendor_partner_id', $this->input('vendor_partner_id')),
            ],

            // OEM partnership compliance — only meaningful when the provider
            // is a vendor. Writes into the same vendor_required_certifications
            // pivot the Vendor Compliance screen already reads, so a
            // requirement recorded here or there is the same fact either way.
            'oem_compliance_required'  => 'boolean',
            'oem_compliance_headcount' => 'nullable|integer|min:1',
            'oem_compliance_notes'     => 'nullable|string|max:1000',

            // Certification detail — only meaningful when certificate_issued
            // is true, but validated permissively either way: the frontend
            // is what enforces "fill this in because you checked that box".
            'certificate_issued' => 'boolean',
            'certification_name' => 'nullable|string|max:200',
            'exam_code'           => 'nullable|string|max:50',
            'exam_duration'       => 'nullable|string|max:50',
            'passing_score'       => 'nullable|integer|min:0|max:100',
            'exam_provider'       => 'nullable|string|max:100',
            'validity_years'      => 'nullable|integer|min:0|max:99',
            'renewal_required'    => 'boolean',
            'cpe_credits'         => 'nullable|integer|min:0',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('training_provider_type');

            if ($type === 'certifying_body' && !$this->filled('certifying_body_id')) {
                $validator->errors()->add('certifying_body_id', 'Choose the certifying professional body.');
            }

            if ($type === 'vendor_oem') {
                if (!$this->filled('vendor_partner_id')) {
                    $validator->errors()->add('vendor_partner_id', 'Choose the vendor.');
                }
                if (empty($this->input('product_ids'))) {
                    $validator->errors()->add('product_ids', 'Select at least one product covered by this training.');
                }
            }
        });
    }
}
