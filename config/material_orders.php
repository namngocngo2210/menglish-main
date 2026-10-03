<?php

/**
 * Order học liệu — cấu hình hạn xử lý (app/Models/MaterialOrder.php).
 *
 * - Đạo cụ / In ấn: Học vụ (CM) xử lý trước 15:00 NGÀY HÔM TRƯỚC ngày sử dụng.
 * - Order GVNN (CM) và học liệu học thuật (Trưởng Học thuật): xử lý đầu tháng → hạn là 23:59 ngày
 *   `start_of_month_day` của THÁNG chứa ngày sử dụng.
 */
return [

    /** Ngày N của tháng: hạn xử lý order GVNN / học liệu học thuật (23:59 ngày N). */
    'start_of_month_day' => 5,

    /** Giờ chốt order đạo cụ / in ấn ở ngày hôm trước ngày sử dụng. */
    'day_before_cutoff_time' => '15:00',

    /** Còn dưới số giờ này tới hạn thì hiện "Sắp hết hạn". */
    'due_soon_hours' => 24,
];
