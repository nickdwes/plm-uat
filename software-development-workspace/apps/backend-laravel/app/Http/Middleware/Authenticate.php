<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * API-only app — never redirect to a login page.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
