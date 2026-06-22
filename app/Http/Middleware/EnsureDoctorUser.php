<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Laravel\Sanctum\PersonalAccessToken;

class EnsureDoctorUser
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return response()->json([
                'message' => 'Invalid token',
            ], 401);
        }

        $user = $accessToken->tokenable;

        if (!$user || !($user instanceof \App\Models\DoctorUser)) {
            return response()->json([
                'message' => 'Unauthorized. Doctor access required.',
            ], 403);
        }

        auth()->setUser($user);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $next($request);
    }
}
