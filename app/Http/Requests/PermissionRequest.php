<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $permissionId = $this->route('permission')?->id;

        return [
            'name' => [
                'required', 'string', 'max:150', 'regex:/^[a-z_]+\.[a-z_]+$/',
                Rule::unique('permissions', 'name')->ignore($permissionId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Tên permission phải theo định dạng "module.action" (chữ thường, gạch dưới).',
        ];
    }
}
