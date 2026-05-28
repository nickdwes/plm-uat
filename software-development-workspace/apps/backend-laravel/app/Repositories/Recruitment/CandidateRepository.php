<?php

namespace App\Repositories\Recruitment;

use App\Models\Recruitment\Candidate;
use App\Repositories\BaseRepository;

class CandidateRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new Candidate());
    }
}
