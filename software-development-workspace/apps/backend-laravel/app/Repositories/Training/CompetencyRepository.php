<?php

namespace App\Repositories\Training;

use App\Models\Training\CompetencyItem;
use App\Repositories\BaseRepository;

class CompetencyRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new CompetencyItem());
    }
}
