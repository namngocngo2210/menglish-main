<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $wasForced = (bool) $request->user()->must_change_password;

        $request->user()->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ]);

        // Đổi mật khẩu tạm lần đầu: về trang hồ sơ không kèm ?force_password (bỏ cảnh báo "mật khẩu tạm").
        if ($wasForced) {
            return redirect()->route('profile.edit')->with('status', 'password-updated');
        }

        return back()->with('status', 'password-updated');
    }
}
