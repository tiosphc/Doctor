<?php

namespace App\Http\Middleware;

use App\Support\CommerceErrorResponder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocalizeCommerceErrors
{
    public function __construct(private readonly CommerceErrorResponder $responder) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->responder->respond($next($request), null, $request);
    }
}
