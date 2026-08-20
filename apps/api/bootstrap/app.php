<?php

use App\Http\Middleware\EnsureOrganizationContext;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\InternalAgentAuth;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            HandleCors::class,
            ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'org.context' => EnsureOrganizationContext::class,
            'agent.internal' => InternalAgentAuth::class,
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);

        $middleware->throttleApi('120,1');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every API error is a structured, predictable envelope. The mobile
        // app and the operations UI must never have to parse an HTML error page.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            [$status, $code] = match (true) {
                $e instanceof ValidationException => [422, 'VALIDATION_FAILED'],
                $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED'],
                $e instanceof AuthorizationException => [403, 'FORBIDDEN'],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [404, 'NOT_FOUND'],
                $e instanceof HttpExceptionInterface => [$e->getStatusCode(), 'HTTP_ERROR'],
                default => [500, 'SERVER_ERROR'],
            };

            $payload = [
                'error' => [
                    'code' => $code,
                    'message' => $status === 500 && ! config('app.debug')
                        ? 'An unexpected error occurred.'
                        : $e->getMessage(),
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ];

            if ($e instanceof ValidationException) {
                $payload['error']['details'] = $e->errors();
            }

            return response()->json($payload, $status);
        });
    })
    ->create();
