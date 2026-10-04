/**
 * Sao chép nội dung vào clipboard rồi báo toast (vd. nội dung xác nhận lịch hẹn để dán vào Zalo).
 *   copyText(text, 'Đã sao chép nội dung xác nhận.')
 *   if (await copyText(text, null)) copied.value = true;   // message null: không toast khi thành công, tự hiện trạng thái "Đã chép"
 * Trả `true` khi đã sao chép, `false` khi trình duyệt chặn (đã báo toast cảnh báo).
 */
import { toast } from '@/lib/toast';

export async function copyText(text, message = 'Đã sao chép.') {
    try {
        await navigator.clipboard.writeText(text);
        if (message) toast(message);
        return true;
    } catch {
        // Trình duyệt chặn clipboard (http, quyền): người dùng bôi đen nội dung và sao chép tay.
        toast('Trình duyệt không cho sao chép tự động — bôi đen nội dung rồi sao chép thủ công.', 'warning');
        return false;
    }
}
