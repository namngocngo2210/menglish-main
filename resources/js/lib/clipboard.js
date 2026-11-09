/**
 * Sao chép nội dung vào clipboard rồi báo toast (vd. nội dung xác nhận lịch hẹn để dán vào tin nhắn).
 *   copyText(text, 'Đã sao chép nội dung xác nhận.')
 *   if (await copyText(text, null)) copied.value = true;   // message null: không toast khi thành công, tự hiện trạng thái "Đã chép"
 * Trả `true` khi đã sao chép, `false` khi trình duyệt chặn (đã báo toast cảnh báo).
 * Ngữ cảnh không bảo mật (http, không có navigator.clipboard) → dùng execCommand('copy') dự phòng.
 */
import { toast } from './toast.js';

function legacyCopy(text) {
    if (typeof document === 'undefined') return false;
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
    document.body.appendChild(area);
    area.select();
    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        document.body.removeChild(area);
    }
}

export async function copyText(text, message = 'Đã sao chép.') {
    let ok = false;
    try {
        await navigator.clipboard.writeText(text);
        ok = true;
    } catch {
        ok = legacyCopy(text);
    }
    if (ok) {
        if (message) toast(message);
        return true;
    }
    // Trình duyệt chặn clipboard (http, quyền): người dùng bôi đen nội dung và sao chép tay.
    toast('Trình duyệt không cho sao chép tự động — bôi đen nội dung rồi sao chép thủ công.', 'warning');
    return false;
}
