<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ParsePutPatchJsonBody
{
    /**
     * PHP does not populate request parameters for PUT/PATCH bodies on many servers.
     * Merge JSON payload manually when the parsed input bag is still empty.
     */
    public function handle(Request $request, Closure $next)
    {
        if (! in_array($request->getRealMethod(), ['PUT', 'PATCH'], true)) {
            return $next($request);
        }

        if ($request->request->count() > 0 || $request->files->count() > 0) {
            return $next($request);
        }

        $content = $request->getContent();
        if (! is_string($content) || $content === '') {
            return $next($request);
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded) && $decoded !== []) {
            $request->merge($decoded);
        }

        return $next($request);
    }
}
