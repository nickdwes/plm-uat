<?php

namespace App\Http\Resources\Training;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'uuid'                 => $this->uuid,
            'training_code'        => $this->training_code,
            'title'                => $this->title,
            'training_version'     => $this->training_version,
            'description'          => $this->description,
            'type'                 => $this->type,
            'delivery'             => $this->delivery,
            'level'                => $this->level,
            'duration_hours'       => $this->duration_hours,
            'duration_minutes'     => $this->duration_minutes,
            'is_long_course'       => $this->is_long_course,
            'provider_name'        => $this->provider_name,
            'provider_url'         => $this->provider_url,
            'cost_currency'        => $this->cost_currency,
            'estimated_cost'       => $this->estimated_cost,
            'tags'                 => $this->tags ?? [],
            'is_active'            => $this->is_active,
            'is_featured'          => $this->is_featured,
            'requires_approval'    => $this->requires_approval,
            'is_budgeted'          => $this->is_budgeted,
            'budget_amount'        => $this->budget_amount,
            'max_enrollments'      => $this->max_enrollments,
            'thumbnail_url'        => $this->thumbnail_url,
            'is_technical'             => $this->is_technical,
            'requires_proctored_exam'  => $this->requires_proctored_exam,
            'certificate_issued'       => $this->certificate_issued,
            'certification_name'       => $this->certification_name,
            'exam_code'                => $this->exam_code,
            'exam_duration'             => $this->exam_duration,
            'passing_score'             => $this->passing_score,
            'exam_provider'             => $this->exam_provider,
            'validity_years'            => $this->validity_years,
            'renewal_required'          => $this->renewal_required,
            'cpe_credits'               => $this->cpe_credits,
            'training_provider_type' => $this->training_provider_type,
            'certifying_body'  => $this->whenLoaded('certifyingBody', fn () => $this->certifyingBody ? [
                'id' => $this->certifyingBody->id,
                'name' => $this->certifyingBody->name,
            ] : null),
            'vendor_partner'   => $this->whenLoaded('vendorPartner', fn () => $this->vendorPartner ? [
                'id' => $this->vendorPartner->id,
                'name' => $this->vendorPartner->name,
            ] : null),
            'products'         => $this->whenLoaded('products', fn () => $this->products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
            ])),
            // The one requirement row (if any) between this item and its own
            // vendor — not the vendor's full requirement list, just whether
            // *this* item counts toward *its* partnership tier.
            'oem_compliance'   => $this->whenLoaded('requiredByVendors', function () {
                $req = $this->vendor_partner_id
                    ? $this->requiredByVendors->firstWhere('id', $this->vendor_partner_id)
                    : null;

                return $req ? [
                    'required_headcount' => $req->pivot->required_headcount,
                    'notes'              => $req->pivot->notes,
                ] : null;
            }),
            'training_records_count' => $this->whenCounted('trainingRecords'),
            'competencies'         => CompetencyItemResource::collection($this->whenLoaded('competencies')),
            'created_at'           => $this->created_at?->toDateTimeString(),
        ];
    }
}
