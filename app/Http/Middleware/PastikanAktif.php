<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PastikanAktif
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()->aktif) {
            Auth::logout();

            return redirect()->route('masuk')->with('pesan', 'Akun Anda dinonaktifkan. Hubungi Dishub.');
        }

        return $next($request);
    }
}
