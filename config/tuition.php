<?php

/**
 * Tham số nghiệp vụ học phí (Phase 4). Có thể ghi đè bằng biến môi trường.
 */
return [

    /**
     * Phí quản trị khi hoàn học phí, tính theo % trên số tiền còn lại sau khi trừ các buổi đã học.
     * (Mockup cũ ghi cứng 10%.)
     */
    'refund_admin_fee_percent' => (float) env('TUITION_REFUND_ADMIN_FEE_PERCENT', 10),

    /**
     * Khoảng ngày (±) để cảnh báo phiếu chuyển khoản tay có thể trùng giao dịch SePay đã tự gạch nợ
     * (cùng học viên / hợp đồng, cùng số tiền).
     */
    'sepay_duplicate_window_days' => (int) env('TUITION_SEPAY_DUPLICATE_WINDOW_DAYS', 3),

    /**
     * Ngưỡng "quá hạn nghiêm trọng" (ngày) trên danh sách thu phí quá hạn, dùng khi chưa cấu hình
     * "Mốc quá hạn bắt buộc liên hệ" trên màn Cấu hình nhắc nợ.
     */
    'overdue_serious_days' => (int) env('TUITION_OVERDUE_SERIOUS_DAYS', 7),

    /** Nhóm "Sắp đến hạn" trên danh sách thu phí: số ngày tới. */
    'upcoming_days' => (int) env('TUITION_UPCOMING_DAYS', 14),
];
