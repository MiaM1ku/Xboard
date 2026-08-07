<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AuthFromCookie
{
    public const COOKIE = 'xboard_session';

    public function handle(Request $request, Closure $next)
    {
        if (!$request->bearerToken() && is_string($request->cookie(self::COOKIE))) {
            $request->headers->set('Authorization', 'Bearer '.$request->cookie(self::COOKIE));
        }

        return $next($request);
    }
}
