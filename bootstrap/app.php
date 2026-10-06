<?php

use App\Exceptions\BusinessException;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureGroupMember;
use App\Http\Middleware\EnsureGroupOwner;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'api.token'    => AuthenticateApiToken::class,
            'group.member' => EnsureGroupMember::class,
            'group.owner'  => EnsureGroupOwner::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $e, Request $request) {
            // Only API routes are forced into the JSON envelope; web routes keep
            // Laravel's default error pages.
            if (! $request->is('api/*')) {
                return null;
            }

            $errors  = null;
            $message = $e->getMessage();
            $status  = Response::HTTP_INTERNAL_SERVER_ERROR;

            if ($e instanceof BusinessException) {
                $status = $e->statusCode();
                $errors = $e->errors();
            } elseif ($e instanceof ValidationException) {
                $status  = Response::HTTP_UNPROCESSABLE_ENTITY;
                $message = 'The given data was invalid.';
                $errors  = $e->errors();
            } elseif ($e instanceof AuthenticationException) {
                $status  = Response::HTTP_UNAUTHORIZED;
                $message = 'Unauthenticated.';
            } elseif ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                $status  = Response::HTTP_NOT_FOUND;
                $message = 'The requested resource was not found.';
            } elseif ($e instanceof MethodNotAllowedHttpException) {
                $status  = Response::HTTP_METHOD_NOT_ALLOWED;
                $message = 'The HTTP method is not supported for this route.';
            } elseif ($e instanceof HttpExceptionInterface) {
                // 405 / 429 and friends keep their status but get a sanitised message.
                $status  = $e->getStatusCode();
                $message = Response::$statusTexts[$status] ?? 'The request could not be completed.';
            } else {
                // Unexpected failure: reported to the log, and the client gets a
                // generic message - no stack traces, no driver internals, no
                // tokens, no passwords.
                report($e);
                $message = 'An unexpected error occurred. Please try again later.';

                // Local debugging aid only. With APP_DEBUG=false (production)
                // this branch never runs, so nothing internal is ever exposed.
                if (config('app.debug')) {
                    $errors = [
                        'debug' => [
                            $e::class.': '.$e->getMessage(),
                            $e->getFile().':'.$e->getLine(),
                        ],
                    ];
                }
            }

            return ApiResponse::error($message, $errors, $status);
        });
    })->create();
    