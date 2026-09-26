<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function attributes(): array
    {
        return ['name' => 'mã vai trò', 'label' => 'tên hiển thị'];
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $roleId = $this->route('role')?->id;

        return [
            'name' => [
                'required', 'string', 'max:100', 'alpha_dash',
                Rule::unique('roles', 'name')->ignore($roleId),
            ],
            'label' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
            // Phạm vi dữ liệu theo module: scope[module] = own | branch | all.
            'scope' => ['array'],
            'scope.*' => ['nullable', Rule::in(['own', 'branch', 'all'])],
        ];
    }
}
