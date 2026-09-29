/**
 * Ô chọn có tìm kiếm (kiểu select2) cho các dropdown LỌC — dùng Tom Select (không cần jQuery).
 *
 * - Tự áp cho mọi <select> nằm trong form GET (thanh lọc / tìm kiếm), và <select data-searchable> ở chỗ khác.
 * - Bỏ qua: <select multiple>, <select data-native>, select nằm trong <template>, và select do Alpine điều khiển
 *   (x-model / option sinh bằng <template x-for>) vì Tom Select chỉ đọc option một lần lúc khởi tạo.
 * - <select> gốc vẫn giữ name / value / sự kiện change (onchange="this.form.submit()" vẫn chạy).
 * - Như select2: danh sách xổ xuống luôn có ô gõ để tìm ở đầu (kể cả khi ít lựa chọn).
 * - Nội dung htmx đổ vào (modal, danh sách) được khởi tạo lại sau htmx:afterSettle.
 */
import TomSelect from 'tom-select/base';
import DropdownInput from 'tom-select/plugins/dropdown_input/plugin.js';

TomSelect.define('dropdown_input', DropdownInput);

const SELECTOR = 'form[method="get" i] select:not([data-native]), form:not([method]) select[data-searchable], select[data-searchable]';
const LAYOUT_CLASS = /^(?:[a-z]+:)*(?:w-|min-w-|max-w-|flex-|basis-|grow|shrink|col-|self-|order-|hidden$|block$)/;

function accessibleName(select) {
    const fromLabel = select.id ? document.querySelector(`label[for="${CSS.escape(select.id)}"]`)?.textContent : null;
    return (select.getAttribute('aria-label') || fromLabel || select.querySelector('option[value=""]')?.textContent || '')
        .replace(/\s+/g, ' ')
        .trim();
}

function enhance(select) {
    if (select.tomselect || select.closest('template') || select.multiple) return;
    if ([...select.attributes].some((a) => a.name.startsWith('x-model')) || select.querySelector('template')) return;

    // Tom Select chép class của <select> sang khung bao; chỉ giữ class bố cục (độ rộng / flex),
    // còn viền, nền, chữ… do .ts-control trong app.css đảm nhiệm (tránh viền kép).
    const styling = [...select.classList].filter((c) => !LAYOUT_CLASS.test(c));
    // Tom Select mặc định bỏ option value="" khỏi danh sách; giữ lại để chọn lại được "Tất cả …".
    new TomSelect(select, {
        allowEmptyOption: true,
        maxOptions: null,
        plugins: ['dropdown_input'],
        placeholder: select.querySelector('option[value=""]')?.textContent?.trim() || undefined,
        render: {
            no_results: () => '<div class="no-results">Không tìm thấy</div>',
        },
        onInitialize() {
            this.wrapper.classList.remove(...styling);
            // Ô gõ tìm nằm trong danh sách xổ xuống (plugin dropdown_input).
            this.control_input?.setAttribute('placeholder', 'Gõ để tìm…');
            // Tên cho trình đọc màn hình: aria-label → <label for> → chữ "Tất cả …" của option rỗng.
            // Gắn cho ô điều khiển (combobox), ô gõ tìm và cả select gốc (vẫn nằm trong cây a11y).
            const name = accessibleName(select);
            if (name) {
                [select, this.control, this.control_input].forEach((el) => el?.setAttribute('aria-label', name));
            }
        },
    });
}

export function initSearchableSelects(root = document) {
    root.querySelectorAll(SELECTOR).forEach(enhance);
}

export function registerSearchableSelects() {
    const run = () => initSearchableSelects();
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
    else run();
    document.addEventListener('htmx:afterSettle', (event) => initSearchableSelects(event.detail.elt ?? document));
}
