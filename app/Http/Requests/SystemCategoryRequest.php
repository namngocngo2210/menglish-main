<?php

namespace App\Http\Requests;

use App\Models\SystemCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SystemCategoryRequest extends FormRequest
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
        $categoryId = $this->route('system_category')?->id;

        return [
            'type' => ['required', Rule::in(SystemCategory::TYPES)],
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('system_categories', 'code')->ignore($categoryId)->where(fn ($q) => $q->where('type', $this->input('type'))),
            ],
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
