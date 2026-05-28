<?php

namespace App\Repositories\Recruitment;

use App\Models\Recruitment\Application;
use App\Repositories\BaseRepository;

class ApplicationRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new Application());
    }
}
