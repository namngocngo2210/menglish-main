<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HolidayRequest extends FormRequest
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
        $holidayId = $this->route('holiday')?->id;

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('holidays', 'code')->ignore($holidayId)],
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_system_wide' => ['boolean'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['exists:branches,id'],
        ];
    }
}
