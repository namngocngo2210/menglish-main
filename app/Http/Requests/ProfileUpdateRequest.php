<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // Email là danh tính đăng nhập và là khóa nhận diện hồ sơ học viên của phụ huynh: đổi email phải xác nhận lại mật khẩu.
            'current_password' => [Rule::requiredIf($this->emailChanged(...)), 'nullable', 'current_password'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Nhập mật khẩu hiện tại để đổi email.',
            'current_password.current_password' => 'Mật khẩu hiện tại không đúng.',
        ];
    }

    /**
     * Tài khoản chỉ dùng cổng học viên / phụ huynh không tự đổi email: email được dùng để khớp hồ sơ học viên, trung tâm
     * (Học vụ / Admin) đổi hộ khi cần.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->emailChanged() && $this->user()->isPortalStudentOnly()) {
                $validator->errors()->add('email', 'Tài khoản học viên / phụ huynh không tự đổi email. Vui lòng liên hệ trung tâm.');
            }
        });
    }

    private function emailChanged(): bool
    {
        return strtolower(trim((string) $this->input('email'))) !== strtolower((string) $this->user()->email);
    }
}
