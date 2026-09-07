<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureUserActive
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && ! $request->user()->active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'تم تعطيل الحساب.');
        }

return $next($request);
    }
}
