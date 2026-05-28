<?php

namespace App\Repositories\Recruitment;

use App\Models\Recruitment\RecruitmentWorkflow;
use App\Repositories\BaseRepository;

class WorkflowRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new RecruitmentWorkflow());
    }
}
