<?php

declare(strict_types=1);

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\ErrorResponse;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ResolveActor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            AssignRequestId::class,
            ResolveActor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API always answers JSON, including for /api routes hit with a form
        // content type or a missing Accept header.
        $exceptions->shouldRenderJsonWhen(static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());

        /*
         * Domain failures are expected control flow, not incidents: a rider
         * racing a driver for the same ride should not page anyone. They are
         * rendered below and deliberately kept out of the error log, so what is
         * in `laravel.log` is only what actually went wrong.
         */
        $exceptions->dontReport([ApiException::class]);

        // Domain failures: the service threw a typed error, render its envelope.
        $exceptions->render(function (ApiException $e, Request $request) {
            return ErrorResponse::make($e->errorCode, $e->getMessage(), $e->details, $request);
        });

        // Validation failures use the same envelope as everything else, with the
        // per-field messages under `details`.
        $exceptions->render(function (ValidationException $e, Request $request) {
            return ErrorResponse::make(
                ApiErrorCode::ValidationError,
                'The request could not be validated',
                $e->errors(),
                $request,
            );
        });

        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) {
            return ErrorResponse::make(ApiErrorCode::NotFound, 'Resource does not exist', null, $request);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            $code = match ($e->getStatusCode()) {
                401 => ApiErrorCode::MissingActor,
                403 => ApiErrorCode::Forbidden,
                404 => ApiErrorCode::NotFound,
                429 => ApiErrorCode::RateLimited,
                default => $e->getStatusCode() >= 500 ? ApiErrorCode::InternalError : ApiErrorCode::ValidationError,
            };

            return ErrorResponse::make($code, $e->getMessage() ?: 'Request failed', null, $request);
        });

        // Anything unexpected: log with context, never leak internals to the client.
        $exceptions->render(function (Throwable $e, Request $request) {
            report($e);

            return ErrorResponse::make(
                ApiErrorCode::InternalError,
                'Something went wrong on our side. Please retry.',
                null,
                $request,
            );
        });
    })->create();
