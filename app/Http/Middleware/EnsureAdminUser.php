<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Laravel\Sanctum\PersonalAccessToken;

class EnsureAdminUser
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get the bearer token from the request
        $token = $request->bearerToken();
        
        if (!$token) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Find the token in the database
        $accessToken = PersonalAccessToken::findToken($token);
        
        if (!$accessToken) {
            return response()->json([
                'message' => 'Invalid token',
            ], 401);
        }

        // Get the tokenable model (should be AdminUser for dashboard)
        $user = $accessToken->tokenable;
        
        // Check if the token belongs to an AdminUser
        if (!$user || !($user instanceof \App\Models\AdminUser)) {
            return response()->json([
                'message' => 'Unauthorized. Admin access required.',
            ], 403);
        }

        // Set the authenticated user for the request
        auth()->setUser($user);
        
        // Also set it for Sanctum guard
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $next($request);
    }
}

