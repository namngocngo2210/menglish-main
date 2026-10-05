<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view. `status` (đã gửi link) hiện trong khung, không lặp lại thành toast.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', ['status' => $request->session()->pull('status')]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        // Cùng một thông báo dù email có tài khoản hay không (hay vừa gửi rồi), để không dò được email nào có tài khoản.
        if (in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return back()->with('status', 'Nếu email này có tài khoản, hệ thống đã gửi link đặt lại mật khẩu. Vui lòng kiểm tra hộp thư (kể cả Spam).');
        }

        return back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
    }
}
