/**
 * Các modal đang mở (UiModal), để Esc chỉ đóng modal trên cùng và khoá cuộn trang nền khi còn modal mở.
 */
const stack = [];

export function pushModal(uid) {
    removeModal(uid);
    stack.push(uid);
    document.documentElement.style.overflow = 'hidden';
}

export function removeModal(uid) {
    const index = stack.indexOf(uid);
    if (index !== -1) stack.splice(index, 1);
    if (!stack.length) document.documentElement.style.overflow = '';
}

export function isTopModal(uid) {
    return stack.at(-1) === uid;
}

export function anyModalOpen() {
    return stack.length > 0;
}

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export function focusables(root) {
    return [...root.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null || el === document.activeElement);
}
