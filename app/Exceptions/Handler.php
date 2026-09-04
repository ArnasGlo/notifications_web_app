<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Determine if the exception handler response should be JSON.
     *
     * API clients (the Android app) must always get JSON, even when they omit the
     * Accept header — otherwise an expired token renders as a 302 to the HTML login
     * page, which HTTP clients follow and report as a successful 200.
     *
     * The legacy /api/numbers/lookup route is excluded: it lives in routes/web.php
     * under session auth and resources/views/messages/compose.blade.php depends on
     * its current behaviour.
     */
    protected function shouldReturnJson($request, Throwable $e): bool
    {
        if ($request->is('api/*') && ! $request->is('api/numbers/lookup')) {
            return true;
        }

        return parent::shouldReturnJson($request, $e);
    }
}
