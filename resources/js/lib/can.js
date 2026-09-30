/**
 * Quyền của user hiện tại (shared prop `can` do HandleInertiaRequests gửi): can('lead.create'), canAny('a', 'b').
 * Quyền trên đối tượng cụ thể (policy) lấy từ props của trang, không dùng hàm này.
 */
import { usePage } from '@inertiajs/vue3';

export function can(ability) {
    return usePage().props.can?.[ability] === true;
}

export function canAny(...abilities) {
    return abilities.flat().some((ability) => can(ability));
}
