<?php

use App\Http\Middleware\EnsureUserActive;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['active' => EnsureUserActive::class]);
        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($request->is('api/*') && in_array((string) $e->getCode(), ['23000', '23505'])) {
                return response()->json(['message' => 'تعارض في البيانات: يوجد سجل مكرر أو ارتباط يمنع العملية.'], 409);
            }
        });
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());
    })
    ->create();
