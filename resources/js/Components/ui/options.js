/**
 * Chuẩn hoá danh sách lựa chọn cho UiSelect: mảng {value, label} (khuyên dùng, giữ đúng thứ tự từ server —
 * App\Support\Ui::options()), mảng [value, label], mảng chuỗi, hoặc object {value: label}.
 */
export function normalizeOptions(options) {
    if (!options) return [];
    if (Array.isArray(options)) {
        return options.map((opt) => {
            if (Array.isArray(opt)) return { value: opt[0], label: opt[1] };
            if (opt !== null && typeof opt === 'object') return { value: opt.value, label: opt.label ?? opt.value, disabled: !!opt.disabled };
            return { value: opt, label: opt };
        });
    }
    return Object.entries(options).map(([value, label]) => ({ value, label }));
}
