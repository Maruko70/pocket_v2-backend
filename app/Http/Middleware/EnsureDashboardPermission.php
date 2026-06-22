<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDashboardPermission
{
    /**
     * Handle an incoming request. Requires one of the given permissions.
     * AdminUser with role 'admin' is granted all permissions.
     *
     * @param  array<string>  $permissions  One or more permission keys (e.g. 'doctors.manage')
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = auth()->user();

        if (!$user || !($user instanceof \App\Models\AdminUser)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($user->role === 'admin') {
            return $next($request);
        }

        $userPermissions = $user->permissions ?? [];
        if (!is_array($userPermissions)) {
            $userPermissions = [];
        }

        foreach ($permissions as $permission) {
            if (in_array($permission, $userPermissions, true)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'You do not have permission to perform this action.',
        ], 403);
    }
}
