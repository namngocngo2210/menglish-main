<?php

/**
 * Order học liệu — hiển thị hạn xử lý (app/Models/MaterialOrder.php).
 * Hạn xử lý (00:00 ngày 1 của tháng dùng cho order GVNN / học liệu học thuật; đạo cụ / in ấn: giờ chốt ngày hôm trước)
 * giờ chốt đạo cụ / in ấn nằm ở trang Cấu hình SLA: material.day_before_cutoff (config/sla.php).
 */
return [

    /** Còn dưới số giờ này tới hạn thì hiện "Sắp hết hạn". */
    'due_soon_hours' => 24,
];
