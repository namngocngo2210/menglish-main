<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /** Vai trò vào thẳng màn làm việc hằng ngày sau đăng nhập (xem homeUrl()). */
    private const TEACHER_ROLES = ['teacher', 'teacher_fulltime', 'teacher_parttime'];

    private const ASSISTANT_ROLES = ['assistant'];

    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended($this->homeUrl($request->user()));
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
    public function destroy(Request $request): RedirectResponse
    {
        if ($user = auth()->user()) {
            activity('auth')->causedBy($user)->log('Đăng xuất');
        }

        auth()->guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
