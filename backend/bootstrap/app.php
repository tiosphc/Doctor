<?php

use App\Http\Middleware\AuthenticateOptionalSanctum;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsCustomer;
use App\Http\Middleware\EnsureUserIsDoctor;
use App\Http\Middleware\EnsureUserIsReceptionist;
use App\Http\Middleware\LocalizeCommerceErrors;
use App\Support\CommerceErrorResponder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->append(LocalizeCommerceErrors::class);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'customer' => EnsureUserIsCustomer::class,
            'doctor' => EnsureUserIsDoctor::class,
            'receptionist' => EnsureUserIsReceptionist::class,
            'optional.sanctum' => AuthenticateOptionalSanctum::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(
            fn (Response $response, Throwable $exception, Request $request): Response => app(CommerceErrorResponder::class)
                ->respond($response, $exception, $request),
        );
    })->create();
