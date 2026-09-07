<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $key = Str::lower($d['email']).'|'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'محاولات كثيرة؛ حاول بعد دقيقة.']);
        }
        if (! Auth::attempt($d + ['active' => true])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'بيانات الدخول غير صحيحة أو الحساب معطل.']);
        }RateLimiter::clear($key);
        $r->session()->regenerate();
        Audit::record('auth.login', auth()->user());

        return redirect('/');
    }

    public function logout(Request $r)
    {
        Audit::record('auth.logout', auth()->user());
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }
}
