<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiToken
{
    public function handle(Request $request, Closure $next)
    {
        $t = (string) config('sender.api_token');
        if (strlen($t) < 20 || ! hash_equals($t, (string) $request->bearerToken())) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
