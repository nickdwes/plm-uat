<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user       = $request->user();
        $orgId      = app('current.organization_id');
        $isHR       = $user?->isOrgHR($orgId);
        $isSelf     = $user && $this->user_id && $user->id === $this->user_id;
        $canSensitive = $isHR || $isSelf;

        $base = [
            'id'                      => $this->id,
            'employee_number'         => $this->employee_number,
            'full_name'               => $this->full_name,
            'display_name'            => $this->display_name,
            'first_name'              => $this->first_name,
            'last_name'               => $this->last_name,
            'middle_name'             => $this->middle_name,
            'preferred_name'          => $this->preferred_name,
            'gender'                  => $this->when($canSensitive, $this->gender),
            'date_of_birth'           => $this->when($canSensitive, $this->date_of_birth?->toDateString()),

            // Contact
            'email'                   => $this->work_email,
            'work_email'              => $this->work_email,
            'personal_email'          => $this->when($canSensitive, $this->personal_email),
            'phone'                   => $this->when($canSensitive, $this->phone),
            'linkedin_profile'        => $this->when($canSensitive, $this->linkedin_profile),
            'address'                 => $this->when($canSensitive, $this->address),
            'residential_address'     => $this->when($canSensitive, $this->residential_address),
            'city'                    => $this->city,
            'country'                 => $this->country,

            // Employment
            'employment_type'         => $this->employment_type,
            'status'                  => $this->status,
            'date_hired'              => $this->date_hired?->toDateString(),
            'date_of_joining'         => $this->date_hired?->toDateString(),
            'probation_end_date'      => $this->probation_end_date?->toDateString(),
            'contract_end_date'       => $this->contract_end_date?->toDateString(),
            'date_exited'             => $this->date_exited?->toDateString(),
            'exit_reason'             => $this->exit_reason,
            'is_shared'               => $this->is_shared,

            // Org placement
            'primary_organization'    => $this->whenLoaded('primaryOrganization', fn () => [
                'id'   => $this->primaryOrganization->id,
                'name' => $this->primaryOrganization->name,
                'slug' => $this->primaryOrganization->slug,
            ]),
            'department'              => $this->whenLoaded('department', fn () => [
                'id'   => $this->department->id,
                'name' => $this->department->name,
            ]),
            'designation'             => $this->whenLoaded('designation', fn () => [
                'id'    => $this->designation->id,
                'name'  => $this->designation->title,
                'title' => $this->designation->title,
            ]),
            'job_grade'               => $this->whenLoaded('jobGrade', fn () => [
                'id'   => $this->jobGrade->id,
                'name' => $this->jobGrade->name,
                'code' => $this->jobGrade->code,
            ]),
            'manager'                 => $this->whenLoaded('manager', fn () => $this->manager ? [
                'id'           => $this->manager->id,
                'full_name'    => $this->manager->full_name,
                'work_email'   => $this->manager->work_email,
            ] : null),

            'created_at'              => $this->created_at?->toISOString(),
            'updated_at'              => $this->updated_at?->toISOString(),
        ];

        // Sensitive-only fields
        if ($canSensitive) {
            $base += [
                // Statutory IDs
                'national_id'         => $this->national_id,
                'passport_number'     => $this->passport_number,
                'tax_id'              => $this->tax_id,
                'nssf_number'         => $this->nssf_number,
                'nhif_number'         => $this->nhif_number,
                'sha_number'          => $this->nhif_number, // SHA = NHIF successor

                // Health
                'blood_group'         => $this->blood_group,
                'allergies'           => $this->allergies,
                'known_conditions'    => $this->known_conditions,
                'disabilities'        => $this->disabilities,

                // Compensation
                'basic_salary'        => $this->basic_salary,
                'house_allowance'     => $this->house_allowance,
                'transport_allowance' => $this->transport_allowance,
                'medical_allowance'   => $this->medical_allowance,
                'other_allowances'    => $this->other_allowances,
                'insurance_relief'    => $this->insurance_relief,

                // Emergency contacts (eager-loaded)
                'emergency_contacts'  => $this->whenLoaded('emergencyContacts', fn () =>
                    $this->emergencyContacts->map(fn ($c) => [
                        'id'           => $c->id,
                        'name'         => $c->name,
                        'relationship' => $c->relationship,
                        'phone'        => $c->phone,
                        'email'        => $c->email,
                        'is_primary'   => $c->is_primary,
                    ])
                ),
            ];
        }

        return $base;
    }
}

