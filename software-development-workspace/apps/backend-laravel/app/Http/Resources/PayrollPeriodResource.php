<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'organization_id' => $this->organization_id,
            'name'         => $this->name,
            'start_date'   => $this->start_date?->toDateString(),
            'end_date'     => $this->end_date?->toDateString(),
            'payment_date' => $this->payment_date?->toDateString(),
            'status'       => $this->status,
            'runs_count'   => $this->whenCounted('runs'),
            'runs'         => $this->whenLoaded('runs', fn () =>
                $this->runs->map(fn ($run) => [
                    'id'               => $run->id,
                    'employee_id'      => $run->employee_id,
                    'employee_name'    => $run->employee?->full_name,
                    'basic_salary'     => (float) $run->basic_salary,
                    'gross_pay'        => (float) $run->gross_pay,
                    'total_deductions' => (float) $run->total_deductions,
                    'net_pay'          => (float) $run->net_pay,
                    'status'           => $run->status,
                ])
            ),
            'created_at'   => $this->created_at?->toISOString(),
        ];
    }
}
