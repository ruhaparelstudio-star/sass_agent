<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WebLoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()
                ->with('login_error', 'Email atau password salah.')
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        $user = $request->user();
        if ($user === null || ! in_array($user->role, [UserRole::TenantAdmin, UserRole::Superadmin], true)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()
                ->with('login_error', 'Role tidak diizinkan untuk login web.')
                ->onlyInput('email');
        }

        if ($user->role === UserRole::TenantAdmin) {
            return redirect('/tenant/dashboard');
        }

        return redirect('/superadmin/dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
