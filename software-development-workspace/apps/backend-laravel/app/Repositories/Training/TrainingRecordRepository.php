<?php

namespace App\Repositories\Training;

use App\Models\Training\EmployeeTrainingRecord;
use App\Repositories\BaseRepository;

class TrainingRecordRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new EmployeeTrainingRecord());
    }
}
