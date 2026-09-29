/**
 * Dòng bảng bấm được: <tr data-href="URL"> — bấm vào bất kỳ đâu trên dòng thì mở URL (vd. ?selected_id= → modal chi tiết).
 *
 * - Bấm vào phần tử tương tác bên trong dòng (link, nút, ô nhập, form) → để phần tử đó tự xử lý.
 * - Ctrl / Cmd / Shift / chuột giữa → mở tab mới như link thường.
 * - Bàn phím: dòng nên chứa 1 <a href> cùng URL (Tab tới link đó rồi Enter), dòng không nhận focus riêng.
 */
const INTERACTIVE = 'a, button, input, select, textarea, label, form, summary, [role="button"], [data-no-row-link]';

function open(event, row) {
    if (event.target.closest(INTERACTIVE) || window.getSelection()?.toString()) return;
    const url = row.dataset.href;
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) {
        window.open(url, '_blank', 'noopener');
        return;
    }
    window.location.href = url;
}

export function registerRowLinks() {
    document.addEventListener('click', (event) => {
        const row = event.target.closest?.('tr[data-href]');
        if (row && event.button === 0) open(event, row);
    });
    document.addEventListener('auxclick', (event) => {
        const row = event.target.closest?.('tr[data-href]');
        if (row && event.button === 1) open(event, row);
    });
}
