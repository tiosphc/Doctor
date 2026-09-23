<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsReceptionist
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isReceptionist(), Response::HTTP_FORBIDDEN, 'Receptionist access is required.');

        return $next($request);
    }
}
