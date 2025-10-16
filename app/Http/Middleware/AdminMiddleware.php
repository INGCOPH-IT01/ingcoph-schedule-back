<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('AdminMiddleware - Checking admin access for route: ' . $request->path());
        
        if (!$request->user()) {
            Log::warning('AdminMiddleware - No authenticated user');
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        Log::info('AdminMiddleware - User ID: ' . $request->user()->id . ', Role: ' . $request->user()->role);

        if (!$request->user()->isAdmin()) {
            Log::warning('AdminMiddleware - User is not admin. Role: ' . $request->user()->role);
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Admin privileges required.',
            ], 403);
        }

        Log::info('AdminMiddleware - Admin access granted');
        return $next($request);
    }
}
