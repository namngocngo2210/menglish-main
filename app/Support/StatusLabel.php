<?php

namespace App\Support;

/**
 * Nhãn tiếng Việt cho các mã trạng thái chung (dùng khi model chưa có accessor riêng),
 * tránh hiện mã thô như `pending_review`, `valid` trên giao diện.
 */
final class StatusLabel
{
    public const LABELS = [
        'active' => 'Đang hoạt động',
        'inactive' => 'Ngừng hoạt động',
        'new' => 'Mới',
        'draft' => 'Nháp',
        'pending' => 'Chờ xử lý',
        'pending_review' => 'Chờ duyệt',
        'pending_approval' => 'Chờ duyệt',
        'pending_confirmation' => 'Chờ xác nhận',
        'submitted' => 'Đã gửi',
        'approved' => 'Đã duyệt',
        'rejected' => 'Từ chối',
        'confirmed' => 'Đã xác nhận',
        'valid' => 'Hợp lệ',
        'invalid' => 'Không hợp lệ',
        'in_progress' => 'Đang thực hiện',
        'assigned' => 'Đã giao',
        'completed' => 'Hoàn thành',
        'done' => 'Hoàn thành',
        'resolved' => 'Đã xử lý',
        'closed' => 'Đã đóng',
        'open' => 'Đang mở',
        'blocked' => 'Bị chặn',
        'overdue' => 'Quá hạn',
        'scheduled' => 'Đã lên lịch',
        'graded' => 'Đã chấm',
        'sent' => 'Đã gửi',
        'paid' => 'Đã thanh toán',
        'unpaid' => 'Chưa thanh toán',
        'partial' => 'Thanh toán một phần',
        'matched' => 'Đã khớp',
        'unmatched' => 'Chưa khớp',
        'processed' => 'Đã xử lý',
        'ignored' => 'Bỏ qua',
        'duplicate' => 'Trùng lặp',
        'failed' => 'Thất bại',
        'success' => 'Thành công',
        'canceled' => 'Đã hủy',
        'cancelled' => 'Đã hủy',
        'studying' => 'Đang học',
        'upcoming' => 'Sắp khai giảng',
        'finished' => 'Đã kết thúc',
    ];

    public static function for(?string $status, string $fallback = 'Chưa cập nhật'): string
    {
        if ($status === null || $status === '') {
            return $fallback;
        }

        return self::LABELS[$status] ?? $status;
    }
}
