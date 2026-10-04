<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Số lần đăng nhập sai tối đa từ một IP (mọi tài khoản) trong IP_DECAY_SECONDS. */
    private const IP_MAX_ATTEMPTS = 30;

    private const IP_DECAY_SECONDS = 600;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Ô "email" nhận email hoặc mã tài khoản (mã học viên HV-… / mã nhân sự ME-…).
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = ['email' => $this->loginEmail(), 'password' => $this->string('password')->toString()];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->ipThrottleKey(), self::IP_DECAY_SECONDS);

            activity('auth')
                ->withProperties(['email' => $this->string('email')->toString()])
                ->log('Đăng nhập thất bại (sai email hoặc mật khẩu)');

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->isLocked()) {
            Auth::logout();
            RateLimiter::hit($this->throttleKey());

            activity('auth')->causedBy($user)->log('Đăng nhập bị từ chối do tài khoản đã bị khóa');

            throw ValidationException::withMessages([
                'email' => 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ quản trị viên.',
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        activity('auth')->causedBy($user)->log('Đăng nhập thành công');

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Email dùng để đăng nhập: nhập email thì dùng nguyên; nhập mã tài khoản (không có "@") thì tra email của
     * tài khoản có mã đó (không phân biệt hoa thường). Không tìm thấy → trả lại chuỗi đã nhập để đăng nhập thất bại bình thường.
     */
    private function loginEmail(): string
    {
        $login = trim($this->string('email')->toString());

        if ($login === '' || str_contains($login, '@')) {
            return $login;
        }

        return User::query()->whereRaw('UPPER(employee_code) = ?', [Str::upper($login)])->value('email') ?? $login;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        // Giới hạn theo (email + IP) chặn đoán mật khẩu một tài khoản; giới hạn theo IP chặn thử mật khẩu phổ biến trên
        // nhiều tài khoản (credential stuffing) mà mỗi tài khoản chỉ bị thử vài lần.
        $key = match (true) {
            RateLimiter::tooManyAttempts($this->throttleKey(), 5) => $this->throttleKey(),
            RateLimiter::tooManyAttempts($this->ipThrottleKey(), self::IP_MAX_ATTEMPTS) => $this->ipThrottleKey(),
            default => null,
        };
        if ($key === null) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    private function ipThrottleKey(): string
    {
        return 'login-ip|'.$this->ip();
    }
}
