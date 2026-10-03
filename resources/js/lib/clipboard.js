/**
 * Sao chép nội dung vào clipboard rồi báo toast (vd. nội dung xác nhận lịch hẹn để dán vào Zalo).
 *   copyText(text, 'Đã sao chép nội dung xác nhận.')
 */
import { toast } from '@/lib/toast';

export async function copyText(text, message = 'Đã sao chép.') {
    try {
        await navigator.clipboard.writeText(text);
        toast(message);
    } catch {
        // Trình duyệt chặn clipboard (http, quyền): người dùng bôi đen nội dung và sao chép tay.
        toast('Trình duyệt không cho sao chép tự động — bôi đen nội dung rồi sao chép thủ công.', 'warning');
    }
}
