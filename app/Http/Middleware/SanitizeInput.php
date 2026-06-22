<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SanitizeInput
{
    public function handle(Request $request, Closure $next)
    {
        $input = $request->all();

        if ($input) {
            array_walk_recursive($input, function (&$value) {
                if (is_string($value)) {
                    // Remove null bytes
                    $value = str_replace(chr(0), '', $value);
                    
                    // Strip HTML tags but preserve basic formatting
                    $value = strip_tags($value);
                    
                    // Trim whitespace
                    $value = trim($value);
                    
                    // Remove excessive whitespace
                    $value = preg_replace('/\s+/', ' ', $value);
                }
            });

            $request->merge($input);
        }

        return $next($request);
    }
}

