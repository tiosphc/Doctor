<?php

namespace App\Http\Middleware;

use App\Models\Doctor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsDoctor
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isDoctor(), Response::HTTP_FORBIDDEN, 'Doctor access is required.');
        abort_unless(! $request->user()->must_change_password, Response::HTTP_FORBIDDEN, 'Password setup is required.');
        abort_unless($request->user()->doctorProfile !== null, Response::HTTP_FORBIDDEN, 'A linked doctor profile is required.');
        abort_unless($request->user()->doctorProfile->status === Doctor::STATUS_ACTIVE, Response::HTTP_FORBIDDEN, 'The doctor account is inactive.');

        return $next($request);
    }
}
