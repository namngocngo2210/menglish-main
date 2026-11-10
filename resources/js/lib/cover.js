/** Nhãn + hành động hiển thị trên màn Dạy thay (TK-2026-0057), tách khỏi Vue để test bằng node --test. */
export const COVER_STATUS = {
    open: { label: 'Đang tìm GV', color: 'warning' },
    approved: { label: 'Đã duyệt', color: 'success' },
    cancelled: { label: 'Đã hủy', color: 'muted' },
};

/**
 * Các nút khả dụng của một yêu cầu với người đang xem: Đăng ký / Bỏ đăng ký (GV khác), Hủy (người đề xuất hoặc người duyệt),
 * Duyệt / Mở lại (người duyệt). Khớp quy tắc CoverService phía server (server vẫn kiểm tra lại).
 */
export function coverActions(cover, { canApprove, userId }) {
    const open = cover.status === 'open';
    const mine = cover.requested_by?.id === userId;
    return {
        register: open && !mine && !cover.i_registered && !canApprove,
        unregister: open && !!cover.i_registered,
        approve: canApprove && open && (cover.registrations?.length ?? 0) > 0,
        reopen: canApprove && cover.status === 'approved',
        cancel: (mine || canApprove) && ['open', 'approved'].includes(cover.status),
    };
}
