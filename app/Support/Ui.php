<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Dữ liệu cho component Vue (resources/js/Components/ui).
 */
final class Ui
{
    /**
     * Danh sách lựa chọn cho <UiSelect :options> dạng [{value, label}] — giữ đúng thứ tự (object JSON {id: tên} bị trình duyệt
     * sắp lại theo khoá số).
     *   Ui::options(Branch::active()->orderBy('name')->get(), 'name')          // value = id
     *   Ui::options(['new' => 'Mới', 'done' => 'Xong'])                        // mảng value => label
     *
     * @param  iterable<mixed>  $items
     * @return list<array{value: mixed, label: mixed}>
     */
    public static function options(iterable $items, string|\Closure|null $label = null, string $value = 'id'): array
    {
        $items = $items instanceof Collection ? $items : collect($items);

        if ($label === null) {
            return $items->map(fn ($text, $key) => ['value' => $key, 'label' => $text])->values()->all();
        }

        return $items->map(fn ($item) => [
            'value' => data_get($item, $value),
            'label' => $label instanceof \Closure ? $label($item) : data_get($item, $label),
        ])->values()->all();
    }
}
