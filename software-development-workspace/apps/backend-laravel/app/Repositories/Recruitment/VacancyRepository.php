<?php

namespace App\Repositories\Recruitment;

use App\Models\Recruitment\Vacancy;
use App\Repositories\BaseRepository;

class VacancyRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new Vacancy());
    }
}
