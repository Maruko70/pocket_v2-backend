<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CacheResponse
{
    public function handle(Request $request, Closure $next, $ttl = 3600): Response
    {
        // Only cache GET requests
        if ($request->method() !== 'GET') {
            return $next($request);
        }

        // Generate cache key from request
        $cacheKey = 'api_' . md5($request->fullUrl());

        // Check if cached response exists
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        // Get response
        $response = $next($request);

        // Cache the response if it's successful
        if ($response->getStatusCode() === 200) {
            $content = json_decode($response->getContent(), true);
            if ($content) {
                Cache::put($cacheKey, $content, $ttl);
            }
        }

        return $response;
    }
}

