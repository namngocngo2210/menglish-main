/** Bật/tắt một trạng thái trong danh sách đang chọn (bộ lọc trạng thái chọn nhiều). */
export function toggleStatus(selected, value) {
    return selected.includes(value) ? selected.filter((s) => s !== value) : [...selected, value];
}
