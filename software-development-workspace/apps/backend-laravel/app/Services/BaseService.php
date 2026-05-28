<?php

namespace App\Services;

use App\Repositories\Contracts\RepositoryInterface;

abstract class BaseService
{
    public function __construct(protected RepositoryInterface $repository) {}
}
