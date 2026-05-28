<?php

namespace App\Repositories\Recruitment;

use App\Models\Recruitment\VacancyTemplate;
use App\Repositories\BaseRepository;

class VacancyTemplateRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new VacancyTemplate());
    }
}
