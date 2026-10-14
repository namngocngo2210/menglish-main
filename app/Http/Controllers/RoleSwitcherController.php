<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Models\User;
use App\Support\RoleSwitcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Đổi vai trò nhanh trên môi trường thử nghiệm (App\Support\RoleSwitcher): đăng nhập thẳng bằng tài khoản mẫu đã chọn,
 * nhớ tài khoản Super Admin gốc trong session để chuyển tiếp / quay về. Tắt (production, chưa bật) → 404.
 */
class RoleSwitcherController extends Controller
{
    public function __invoke(Request $request, User $user, RoleSwitcher $switcher): Response
    {
        $origin = $switcher->origin($request);
        abort_unless($origin, 404);
        abort_unless($switcher->canSwitchTo($origin, $user), 403, 'Tài khoản này không có trong danh sách đổi vai trò.');

        $from = $request->user();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->forget('url.intended');

        if ($user->is($origin)) {
            $request->session()->forget(RoleSwitcher::SESSION_KEY);
        } else {
            $request->session()->put(RoleSwitcher::SESSION_KEY, $origin->id);
        }

        activity('auth')
            ->causedBy($origin)
            ->performedOn($user)
            ->withProperties(['from_user_id' => $from->id])
            ->log("Đổi vai trò thử nghiệm: {$from->name} → {$user->name}");

        // Như đăng nhập: tải lại hẳn trang đầu của tài khoản mới (phiên, token CSRF, menu đổi hết).
        return Inertia::location(redirect(AuthenticatedSessionController::homeUrl($user)));
    }
}
