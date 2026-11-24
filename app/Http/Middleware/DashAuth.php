<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DashAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->session()->get('hs_ok')) {
            return redirect('/login');
        }

        return $next($request);
    }
}
