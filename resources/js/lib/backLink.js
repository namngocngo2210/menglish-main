/**
 * Nút "Quay lại" về đúng trang người dùng vừa xử lý (giữ bộ lọc, tab, trang của danh sách),
 * thay vì luôn về trang gốc cố định.
 *
 *   const back = useBackLink(() => route('students.index'), 'Danh sách học sinh');
 *   <UiButton variant="secondary" icon="arrow_back" :href="back.href" data-back-link>{{ back.label }}</UiButton>
 *
 * UiPageHeader `back` / UiModalFrame `back` đã dùng sẵn — trang chỉ cần truyền URL dự phòng như cũ.
 * Link quay lại tự làm phải có `data-back-link` (bấm vào = lùi trong vệt, không tính là mở trang mới).
 *
 * Cách làm: mỗi tab trình duyệt giữ một "vệt" các trang đã mở (sessionStorage), mỗi lần mở một trang là một mục
 * với URL đầy đủ (kèm ?lọc, ?tab, ?page — đổi lọc / tab tại chỗ chỉ cập nhật mục đó). Nút Quay lại = mục ngay trước
 * trang hiện tại; vệt trống (tab mới, link từ ngoài, vừa đăng nhập) → URL dự phòng.
 *   - Bấm Quay lại / nút Back của trình duyệt → cắt vệt về trang đích.
 *   - Gửi form (lưu, duyệt, xoá…) → trang kết quả thay chỗ trang form: sửa lớp → lưu → về chi tiết lớp,
 *     Quay lại tiếp là về danh sách chứ không vòng lại form sửa.
 */
import { computed, onMounted, reactive, ref, shallowRef } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const KEY = 'menglish.backTrail';
const MAX = 30;
const BASE = 'http://localhost';

const trail = shallowRef([]);

const pathOf = (url) => new URL(url, BASE).pathname;
const relative = (url) => {
    const u = new URL(url, BASE);
    return u.pathname + u.search;
};

function load() {
    try {
        const saved = JSON.parse(window.sessionStorage.getItem(KEY));
        return Array.isArray(saved) ? saved.filter((item) => typeof item === 'string') : [];
    } catch {
        return [];
    }
}

function save(next) {
    trail.value = next.slice(-MAX);
    try {
        window.sessionStorage.setItem(KEY, JSON.stringify(trail.value));
    } catch {
        // Trình duyệt chặn sessionStorage → vẫn chạy trong phiên trang hiện tại.
    }
}

/**
 * Ghi trang vừa mở vào vệt.
 *   kind: null (mở link) | 'replace' (gửi form, tải lại một phần, visit replace) | 'back' (Quay lại / Back trình duyệt)
 */
function record(url, kind) {
    const href = relative(url);
    const path = pathOf(href);
    const next = [...trail.value];
    const top = next[next.length - 1];

    if (kind === 'replace' || (top && pathOf(top) === path)) {
        next.pop();
    } else if (kind === 'back') {
        const index = next.findLastIndex((item) => pathOf(item) === path);
        if (index >= 0) next.length = index;
    }
    next.push(href);
    // Trang kết quả trùng trang ngay trước (sửa → lưu → về chi tiết) → gộp một mục.
    if (next.length > 1 && pathOf(next[next.length - 2]) === path) next.splice(-2, 1);
    save(next);
}

/** URL trang ngay trước trang `currentUrl` trong vệt (null nếu không có). */
function previousOf(currentUrl) {
    const path = pathOf(currentUrl);
    const items = trail.value;
    const index = items.findLastIndex((item) => pathOf(item) === path);
    const previous = index >= 0 ? items[index - 1] : items[items.length - 1];
    return previous && pathOf(previous) !== path ? previous : null;
}

/** app.js gọi một lần: ghi lại mọi lần chuyển trang Inertia (kể cả Back/Forward của trình duyệt, F5). */
export function registerBackTrail() {
    if (typeof window === 'undefined') return;
    trail.value = load();

    // Kiểu của lần chuyển trang sắp tới (xem record).
    let pending = window.performance?.getEntriesByType?.('navigation')?.[0]?.type === 'back_forward' ? 'back' : null;
    let backClicked = false;

    // Pha capture: chạy trước Link của Inertia (Link gọi router.visit ngay trong click).
    document.addEventListener(
        'click',
        (event) => {
            backClicked = !!event.target.closest?.('[data-back-link]');
        },
        true,
    );
    // Back / Forward của trình duyệt (popstate của modal xem nhanh bị remoteModal chặn trước, không tới đây).
    window.addEventListener('popstate', () => {
        pending = 'back';
    });

    router.on('start', (event) => {
        const visit = event.detail.visit;
        if (visit.prefetch) return;
        const partial = !!visit.only?.length;
        pending = visit.method !== 'get' || visit.replace || partial ? 'replace' : backClicked ? 'back' : null;
        backClicked = false;
        if (visit.method !== 'get' || partial) return;
        // Trước khi rời trang: URL thật có thể đã đổi ngoài Inertia (đóng modal chi tiết → replaceState bỏ ?id)
        // → cập nhật mục của trang hiện tại để lúc quay lại không mở lại modal đó.
        const items = trail.value;
        const here = window.location.pathname + window.location.search;
        if (items.length && pathOf(items[items.length - 1]) === window.location.pathname && items[items.length - 1] !== here) {
            save([...items.slice(0, -1), here]);
        }
    });

    const onPage = (event) => {
        const page = event.detail.page;
        const kind = pending;
        pending = null;
        // Trang đăng nhập / quên mật khẩu: bắt đầu vệt mới (đổi tài khoản trong cùng tab không quay về trang của người trước).
        if (page.component?.startsWith('Auth/')) save([]);
        else record(page.url, kind);
    };
    router.on('navigate', onPage);
    // Lượt tải replace (đổi lọc tại chỗ, tải lại một phần) không phát 'navigate'; lượt thường phát cả hai → lần sau
    // trùng trang trên cùng, chỉ cập nhật lại URL.
    router.on('success', onPage);
}

/**
 * Link quay lại cho trang hiện tại: { href, label, fromHistory }.
 *   fallback: URL dự phòng (string hoặc hàm trả string) — trang cha cố định như trước đây.
 *   label: nhãn khi về đúng trang dự phòng; về trang khác thì nhãn là "Quay lại".
 */
export function useBackLink(fallback, label = 'Quay lại') {
    const page = usePage();
    // Chỉ đọc vệt sau khi gắn vào trang (render phía server không có sessionStorage).
    const mounted = ref(false);
    onMounted(() => {
        mounted.value = true;
    });

    const fallbackHref = computed(() => (typeof fallback === 'function' ? fallback() : fallback) || null);
    const previous = computed(() => (mounted.value ? previousOf(page.url) : null));

    return reactive({
        href: computed(() => previous.value ?? fallbackHref.value),
        fromHistory: computed(() => !!previous.value),
        label: computed(() => {
            const target = previous.value;
            const sameAsFallback = !target || (fallbackHref.value && pathOf(target) === pathOf(fallbackHref.value));
            return sameAsFallback ? label : 'Quay lại';
        }),
    });
}
