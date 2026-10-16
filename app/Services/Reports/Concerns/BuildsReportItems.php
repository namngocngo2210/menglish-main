<?php

namespace App\Services\Reports\Concerns;

use App\Models\Branch;
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

    /**
     * Lớp tính vào báo cáo: lớp người viết được xem, chỉ trong các chi nhánh của người viết (Học vụ có quyền xem lớp
     * toàn hệ thống nhưng báo cáo chỉ tính chi nhánh mình, tránh đếm trùng khi cộng báo cáo các chi nhánh).
     */
    private function classIds(): array
    {
        return $this->classIds ??= ClassModel::query()->visibleTo($this->user)
            ->when($this->reportBranchIds() !== [], fn ($q) => $q->whereIn('branch_id', $this->reportBranchIds()))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Chi nhánh của người viết (rỗng = không gán chi nhánh, không lọc thêm). */
    private function reportBranchIds(): array
    {
        return array_values(array_map('intval', $this->user->branchIds()));
    }

    /**
     * Người phụ trách nhiều chi nhánh: dòng tách số theo từng chi nhánh (rỗng nếu chỉ một chi nhánh).
     *
     * @param  iterable<int|null>  $branchIds  chi nhánh của từng bản ghi được đếm
     * @return list<array{text: string, tag: string}>
     */
    private function branchSplit(iterable $branchIds, string $unit): array
    {
        if (count($this->reportBranchIds()) < 2) {
            return [];
        }
        $names = Branch::whereIn('id', $this->reportBranchIds())->pluck('name', 'id');
        $counts = collect($branchIds)->countBy(fn ($id) => (int) $id);

        return $names->map(fn (string $name, int $id) => ['text' => "{$name}: ".($counts[$id] ?? 0)." {$unit}", 'tag' => 'Chi nhánh'])->values()->all();
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
