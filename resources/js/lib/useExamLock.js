/**
 * Chế độ khoá màn hình khi làm bài (test đầu vào, Luyện tập).
 *   const lock = useExamLock({ maxViolations: 3, onViolation: (type, count) => …, onLimit: () => … });
 *   lock.start()   — vào toàn màn hình, bật khoá (gọi từ thao tác bấm nút của người dùng);
 *   lock.resume()  — đóng cảnh báo, bật lại khoá nếu đã stop(), vào lại toàn màn hình;
 *   lock.stop()    — đang nộp bài: ngừng ghi rời bài, cho rời trang / quay lại.
 * Rời bài (ẩn tab / mất focus / thoát toàn màn hình) được ghi vào `log`; các sự kiện bắn cùng lúc trong 1,5 giây tính
 * là 1 lần. Dưới giới hạn: `showWarning` = true; tới `maxViolations`: tự stop() rồi gọi onLimit().
 * Khi đang làm bài: chặn quay lại / tải lại trang, chuột phải, sao chép / dán, kéo thả, bôi đen và phím tắt phổ biến.
 * Chỉ mang tính răn đe trên trình duyệt (không chặn chụp màn hình / máy thứ hai).
 * Gọi trong setup của component; listener gỡ và thoát toàn màn hình khi component bị huỷ.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';

const DEDUPE_MS = 1500;
const BLOCKED_EVENTS = ['contextmenu', 'copy', 'cut', 'paste', 'drop', 'dragstart'];

const root = () => document.documentElement;
const canFullscreen = () => !!(root().requestFullscreen || root().webkitRequestFullscreen);
const isFullscreen = () => !!(document.fullscreenElement || document.webkitFullscreenElement);

function enterFullscreen() {
    if (!canFullscreen() || isFullscreen()) return;
    const req = root().requestFullscreen || root().webkitRequestFullscreen;
    try {
        const p = req.call(root());
        if (p && p.catch) p.catch(() => {});
    } catch (e) {
        /* trình duyệt từ chối toàn màn hình: vẫn cho làm bài */
    }
}

function exitFullscreen() {
    if (!isFullscreen()) return;
    const exit = document.exitFullscreen || document.webkitExitFullscreen;
    try {
        const p = exit.call(document);
        if (p && p.catch) p.catch(() => {});
    } catch (e) {
        /* bỏ qua */
    }
}

export function useExamLock({ maxViolations = 3, onViolation = () => {}, onLimit = () => {} } = {}) {
    // active: đã bắt đầu làm bài (giữ nguyên sau stop() để trang vẫn hiện bài). stopped: đang nộp bài, tạm tắt khoá.
    const active = ref(false);
    const log = ref([]);
    const showWarning = ref(false);
    let stopped = false;
    let lastViolationAt = 0;

    const armed = () => active.value && !stopped;

    function record(type) {
        if (!armed()) return;
        // Chuyển tab thường bắn cùng lúc blur + visibilitychange + thoát toàn màn hình: tính là 1 lần.
        const now = Date.now();
        if (now - lastViolationAt < DEDUPE_MS) return;
        lastViolationAt = now;

        log.value.push({ type, at: new Date().toISOString() });
        const count = log.value.length;
        onViolation(type, count);
        if (count >= maxViolations) {
            stop();
            showWarning.value = false;
            onLimit();
            return;
        }
        showWarning.value = true;
    }

    function start() {
        enterFullscreen();
        active.value = true;
        stopped = false;
        // Khoá nút Quay lại (xem onPopstate): mục lịch sử hiện tại thành state rỗng + thêm một mục trùng trang ở trên.
        history.replaceState(null, '', location.href);
        history.pushState(null, '', location.href);
    }

    function resume() {
        stopped = false;
        showWarning.value = false;
        enterFullscreen();
    }

    function stop() {
        stopped = true;
    }

    const onVisibility = () => {
        if (document.visibilityState === 'hidden') record('hidden');
    };
    const onBlur = () => record('blur');
    const onFullscreenChange = () => {
        if (canFullscreen() && !isFullscreen()) record('fullscreen_exit');
    };
    // Không cho quay lại trang trước khi đang làm bài. Bấm Quay lại chỉ về mục lịch sử state rỗng của chính trang thi:
    // với state rỗng Inertia không dựng lại trang (bài đang làm còn nguyên), chỉ ghi state trang vào mục đó và cuộn lên
    // đầu. Bộ xử lý này chạy sau Inertia: đặt lại state rỗng cho mục đó (để lần Quay lại sau vẫn vậy), đẩy lại mục trùng
    // trang và trả vị trí cuộn cũ.
    let lastScrollY = 0;
    const onScroll = () => {
        lastScrollY = window.scrollY;
    };
    const onPopstate = () => {
        if (!armed()) return;
        const y = lastScrollY;
        history.replaceState(null, '', location.href);
        history.pushState(null, '', location.href);
        requestAnimationFrame(() => requestAnimationFrame(() => window.scrollTo(0, y)));
    };
    const onBeforeUnload = (e) => {
        if (armed()) {
            e.preventDefault();
            e.returnValue = '';
        }
    };
    // Chặn chuột phải, sao chép / dán, kéo thả, bôi đen (trừ ô nhập) — cả khi đang nộp bài, như trước khi tách.
    const blockWhileActive = (e) => {
        if (active.value) e.preventDefault();
    };
    const onSelectStart = (e) => {
        if (active.value && !e.target.closest?.('input, textarea')) e.preventDefault();
    };
    // Chặn phím tắt phổ biến: DevTools, xem nguồn, lưu, in, tải lại, sao chép / dán.
    const onKeydown = (e) => {
        if (!armed()) return;
        const key = (e.key || '').toLowerCase();
        const mod = e.ctrlKey || e.metaKey;
        const blocked =
            key === 'f12' ||
            key === 'f5' ||
            (mod && e.shiftKey && ['i', 'j', 'c', 'k'].includes(key)) ||
            (mod && ['u', 's', 'p', 'r', 'c', 'x', 'v', 'o', 'f', 'g'].includes(key)) ||
            (e.altKey && ['arrowleft', 'arrowright'].includes(key));
        if (blocked) {
            e.preventDefault();
            e.stopPropagation();
        }
    };

    // Chỉ chạy trên trình duyệt (SSR không gọi onMounted).
    onMounted(() => {
        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('blur', onBlur);
        document.addEventListener('fullscreenchange', onFullscreenChange);
        document.addEventListener('webkitfullscreenchange', onFullscreenChange);
        window.addEventListener('popstate', onPopstate);
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('beforeunload', onBeforeUnload);
        BLOCKED_EVENTS.forEach((evt) => document.addEventListener(evt, blockWhileActive));
        document.addEventListener('selectstart', onSelectStart);
        document.addEventListener('keydown', onKeydown, true);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisibility);
        window.removeEventListener('blur', onBlur);
        document.removeEventListener('fullscreenchange', onFullscreenChange);
        document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
        window.removeEventListener('popstate', onPopstate);
        window.removeEventListener('scroll', onScroll);
        window.removeEventListener('beforeunload', onBeforeUnload);
        BLOCKED_EVENTS.forEach((evt) => document.removeEventListener(evt, blockWhileActive));
        document.removeEventListener('selectstart', onSelectStart);
        document.removeEventListener('keydown', onKeydown, true);
        // Nộp xong chuyển sang trang khác: thoát toàn màn hình như khi tải trang mới.
        exitFullscreen();
    });

    return { active, log, showWarning, start, resume, stop };
}
