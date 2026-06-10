<?php

namespace App\Repositories\Training;

use App\Models\Training\TrainingItem;
use App\Repositories\BaseRepository;

class TrainingItemRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new TrainingItem());
    }
}
