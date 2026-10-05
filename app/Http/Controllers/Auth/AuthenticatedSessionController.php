<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthenticatedSessionController extends Controller
{
    /** Vai trò vào thẳng màn làm việc hằng ngày sau đăng nhập (xem homeUrl()). */
    private const TEACHER_ROLES = Roles::TEACHERS;

    private const ASSISTANT_ROLES = [Roles::ASSISTANT];

    /**
     * Display the login view. Thông báo `status` (vd. vừa đặt lại mật khẩu) hiện trong khung đăng nhập, không lặp lại thành toast.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'status' => $request->session()->pull('status'),
            'canResetPassword' => Route::has('password.request'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): SymfonyResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Phiên vừa đổi (token CSRF, user) → tải lại hẳn trang đích (Inertia: 409 + X-Inertia-Location); request thường: redirect như cũ.
        return Inertia::location(redirect()->intended($this->homeUrl($request->user())));
    }

    /**
     * Trang đầu sau đăng nhập: giáo viên vào Cổng Giáo viên, trợ giảng vào Nhiệm vụ trợ giảng (màn họ dùng hằng ngày);
     * chỉ áp dụng khi mọi vai trò của người dùng đều là GV / TA — người kiêm vai trò khác vẫn vào Tổng quan.
     */
    private function homeUrl(?User $user): string
    {
        $roles = $user?->getRoleNames() ?? collect();
        if ($roles->isNotEmpty() && $roles->diff(self::TEACHER_ROLES)->isEmpty() && $user->can('attendance_student.record')) {
            return route('teacher.home', absolute: false);
        }
        if ($roles->isNotEmpty() && $roles->diff(self::ASSISTANT_ROLES)->isEmpty()) {
            return route('portal.ta-tasks', absolute: false);
        }

        return route('dashboard', absolute: false);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): SymfonyResponse
    {
        if ($user = auth()->user()) {
            activity('auth')->causedBy($user)->log('Đăng xuất');
        }

        auth()->guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        // Tải lại hẳn trang (không giữ dữ liệu của phiên cũ trong ứng dụng Vue đang chạy).
        return Inertia::location(redirect('/'));
    }
}
