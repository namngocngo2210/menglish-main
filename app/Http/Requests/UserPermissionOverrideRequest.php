<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserPermissionOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Dữ liệu gửi lên là ma trận: overrides[module][action] = 'allow' | 'deny' | 'inherit'.
     * "inherit" nghĩa là xóa override, dùng lại quyền theo role.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'overrides' => ['array'],
            'overrides.*.*' => ['in:allow,deny,inherit'],
        ];
    }
}
