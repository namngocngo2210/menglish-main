/**
 * route() của Laravel trong Vue (Ziggy). Danh sách route sinh sẵn ở ./ziggy.js — đổi routes/web.php xong chạy
 * `php artisan ziggy:generate resources/js/ziggy.js` (test ZiggyRoutesTest báo khi file cũ).
 *
 *   route('crm.customers.show', customer.id)          → "/crm/customers/5" (URL tương đối, không kèm domain)
 *   route('holidays.index', { year: 2026 })           → "/holidays?year=2026"
 *   routeIs('crm.*')                                   → route hiện tại có khớp mẫu không
 */
import { usePage } from '@inertiajs/vue3';
import { route as ziggyRoute } from 'ziggy-js';
import { Ziggy } from '../ziggy';

function config() {
    // Render phía server: không có window.location → dùng URL của trang Inertia đang render.
    const base = typeof window !== 'undefined' ? window.location : new URL(usePage().url ?? '/', 'http://localhost');

    return {
        ...Ziggy,
        url: base ? base.origin : Ziggy.url,
        port: null,
        location: base ? { host: base.host, pathname: base.pathname, search: base.search } : undefined,
    };
}

export function route(name, params, absolute = false) {
    return ziggyRoute(name, params, absolute, config());
}

export function routeIs(...patterns) {
    const current = ziggyRoute(undefined, undefined, undefined, config());
    return patterns.some((pattern) => current.current(pattern));
}
