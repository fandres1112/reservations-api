<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            'throttle:api',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function ($request, $e) {
            if ($request->is('api/*')) {
                return true;
            }
            return $request->expectsJson();
        });

        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'El recurso solicitado no fue encontrado.'
                ], 404);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                $previous = $e->getPrevious();
                if ($previous instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El recurso solicitado no fue encontrado.'
                    ], 404);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'La ruta solicitada no existe.'
                ], 404);
            }
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Los datos proporcionados no son válidos.',
                    'errors' => $e->errors()
                ], 422);
            }
        });

        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->is('api/*') && !config('app.debug')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ocurrió un error interno en el servidor.'
                ], 500);
            }
        });
    })->create();
