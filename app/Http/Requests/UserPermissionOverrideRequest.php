<?php

namespace App\Http\Requests;

use App\Models\UserPermissionOverride;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserPermissionOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Dữ liệu gửi lên là ma trận: overrides[module][action] = 'allow' | 'deny' | 'inherit'.
     * "inherit" nghĩa là xóa override, dùng lại quyền theo role.
     * Phạm vi áp dụng (Lớp học): scope[module][type] = all|branch|class, scope[module][ids][] = id.
     * Phạm vi dữ liệu: data_scope[module] = inherit|own|branch|all. Action động có dấu "." gửi bằng ":".
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'overrides' => ['array'],
            'overrides.*' => ['array'],
            'overrides.*.*' => ['in:allow,deny,inherit'],
            'scope' => ['array'],
            'scope.*.type' => ['nullable', Rule::in([
                UserPermissionOverride::SCOPE_ALL,
                UserPermissionOverride::SCOPE_BRANCH,
                UserPermissionOverride::SCOPE_CLASS,
            ])],
            'scope.*.ids' => ['nullable', 'array'],
            'scope.*.ids.*' => ['integer'],
            // Phạm vi dữ liệu theo module: data_scope[module] = inherit | own | branch | all.
            'data_scope' => ['array'],
            'data_scope.*' => ['nullable', Rule::in(['inherit', 'own', 'branch', 'all'])],
        ];
    }
}
