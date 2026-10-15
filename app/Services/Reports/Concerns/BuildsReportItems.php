<?php

namespace App\Services\Reports\Concerns;

use App\Models\ClassModel;
use Illuminate\Support\Facades\Route;

/**
 * Khung mục số liệu chỉ đọc dùng chung cho báo cáo ngày / tuần (DailyReportMetrics, WeeklyReportMetrics):
 * mỗi mục có `value` (số hiển thị), `info` (cách đếm, icon "i"), `details` (danh sách chi tiết, dòng có thể kèm `tag` / `tone` / `url`).
 * Lớp dùng trait phải có thuộc tính `$user` (người viết báo cáo).
 */
trait BuildsReportItems
{
    /** @var list<int>|null */
    private ?array $classIds = null;

    /** Lớp người viết được xem (cùng phạm vi màn Lớp học). */
    private function classIds(): array
    {
        return $this->classIds ??= ClassModel::query()->visibleTo($this->user)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function section(string $title, string $icon, array $items, ?string $empty = null): array
    {
        return ['title' => $title, 'icon' => $icon, 'items' => $items, 'empty' => $empty];
    }

    /** `count` là số dùng cho ô tổng hợp; mặc định là `value` khi `value` là số. */
    private function item(string $key, string $label, int|string|null $value, string $info, array $details = [], ?int $count = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value === null ? null : (string) $value,
            'count' => $count ?? (is_int($value) ? $value : null),
            'info' => $info,
            'details' => array_values(array_map(fn (array $d) => array_filter($d, fn ($v) => $v !== null), $details)),
        ];
    }

    private function link(string $name, mixed $params = []): ?string
    {
        return Route::has($name) ? route($name, $params) : null;
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 0, ',', '.').'đ';
    }
}
