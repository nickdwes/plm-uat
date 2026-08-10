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
            'photo_url'               => $this->photo_url,
            'full_name'               => $this->full_name,
            'display_name'            => $this->display_name,
            'first_name'              => $this->first_name,
            'last_name'               => $this->last_name,
            'middle_name'             => $this->middle_name,
            'preferred_name'          => $this->preferred_name,
            'gender'                  => $this->when($canSensitive, $this->gender),
            'marital_status'          => $this->when($canSensitive, $this->marital_status),
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
            'county'                  => $this->county,
            'country'                 => $this->country,

            // Profile / career narrative
            'professional_summary'    => $this->professional_summary,
            'about_me'                => $this->about_me,
            'hobbies_interests'       => $this->hobbies_interests,
            'job_description'         => $this->job_description,

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

            'direct_reports'          => $this->whenLoaded('directReports', fn () =>
                $this->directReports->map(fn ($dr) => [
                    'id'          => $dr->id,
                    'full_name'   => $dr->full_name,
                    'work_email'  => $dr->work_email,
                    'designation' => $dr->designation ? ['title' => $dr->designation->title] : null,
                ])
            ),

            'qualifications'          => $this->whenLoaded('qualifications', fn () =>
                $this->qualifications->map(fn ($q) => [
                    'id'                 => $q->id,
                    'title'              => $q->title,
                    'institution'        => $q->institution,
                    'qualification_type' => $q->qualification_type,
                    'field_of_study'     => $q->field_of_study,
                    'year_obtained'      => $q->year_obtained,
                    'certificate_number' => $q->certificate_number,
                    'notes'              => $q->notes,
                ])
            ),

            /*
             * Read from the organisation map, which is where a secondment now
             * lives. These fields were still being read off the free-text table
             * that was dropped — `organization_name`, `start_date` and the rest
             * are columns that no longer exist, so every one of them came back
             * null and the panel looked empty however it had been filled in.
             */
            'secondments'             => $this->whenLoaded('secondments', fn () =>
                $this->secondments->map(fn ($s) => [
                    'id'                => $s->id,
                    'organization_id'   => $s->id,
                    'organization_name' => $s->name,
                    'role'              => $s->pivot->role,
                    'engagement_type'   => $s->pivot->engagement_type,
                    'access_scope'      => $s->pivot->access_scope,
                    'allocation_pct'    => $s->pivot->allocation_pct,
                    'is_billable'       => (bool) $s->pivot->is_billable,
                    'is_active'         => (bool) $s->pivot->is_active,
                    'start_date'        => $s->pivot->effective_from,
                    'end_date'          => $s->pivot->effective_to,
                    'notes'             => $s->pivot->notes,
                ])
            ),

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

                /*
                 * Benefits and statutory registration.
                 *
                 * The onboarding wizard writes all of these and none came back,
                 * so opening somebody to change one field re-sent every
                 * benefit flag as false and blanked their plan and provider.
                 * A form that cannot read what it wrote quietly destroys it.
                 */
                'medical_plan'            => $this->medical_plan,
                'pension_provider'        => $this->pension_provider,
                'benefits_effective_date' => $this->benefits_effective_date?->toDateString(),
                'benefit_medical'         => (bool) $this->benefit_medical,
                'benefit_pension'         => (bool) $this->benefit_pension,
                'benefit_life_insurance'  => (bool) $this->benefit_life_insurance,
                'benefit_group_life'      => (bool) $this->benefit_group_life,
                'benefit_wiba'            => (bool) $this->benefit_wiba,

                'nssf_registered'     => (bool) $this->nssf_registered,
                'nhif_registered'     => (bool) $this->nhif_registered,
                'kra_registered'      => (bool) $this->kra_registered,
                'has_work_permit'     => (bool) $this->has_work_permit,
                'work_permit_number'  => $this->work_permit_number,
                'work_permit_expiry'  => $this->work_permit_expiry?->toDateString(),

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

