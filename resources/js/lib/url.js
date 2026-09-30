/**
 * URL của trang hiện tại (theo Inertia, chạy được cả khi render phía server).
 *   currentQuery()                 → URLSearchParams của trang đang mở
 *   urlWith({ page: 2 })           → URL hiện tại, đổi / thêm tham số (null / '' = bỏ)
 */
import { usePage } from '@inertiajs/vue3';

const BASE = 'http://localhost';

export function currentUrl() {
    return new URL(usePage().url ?? '/', BASE);
}

export function currentQuery() {
    return currentUrl().searchParams;
}

export function urlWith(params = {}, base = null) {
    const url = base ? new URL(base, BASE) : currentUrl();
    for (const [key, value] of Object.entries(params)) {
        if (value === null || value === undefined || value === '') url.searchParams.delete(key);
        else url.searchParams.set(key, value);
    }
    return url.pathname + url.search;
}

/** Bỏ tham số rỗng khỏi dữ liệu form GET (URL gọn: ?status=new thay vì ?search=&status=new&branch_id=). */
export function compactQuery(data) {
    return Object.fromEntries(Object.entries(data).filter(([, v]) => !(v === '' || v === null || v === undefined || (Array.isArray(v) && !v.length))));
}
