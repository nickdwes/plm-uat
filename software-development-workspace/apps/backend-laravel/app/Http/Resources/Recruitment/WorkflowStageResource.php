<?php

namespace App\Http\Resources\Recruitment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowStageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'workflow_id'      => $this->workflow_id,
            'name'             => $this->name,
            'order_index'      => $this->order_index,
            'stage_type'       => $this->stage_type,
            'coordinator_ids'  => $this->coordinator_ids ?? [],
            'required_actions' => $this->required_actions ?? [],
            'entry_criteria'   => $this->entry_criteria ?? [],
            'exit_criteria'    => $this->exit_criteria ?? [],
        ];
    }
}
