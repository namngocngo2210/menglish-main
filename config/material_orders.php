<?php

/**
 * Order học liệu — hiển thị hạn xử lý (app/Models/MaterialOrder.php).
 * Hạn xử lý (ngày N đầu tháng cho order GVNN / học liệu học thuật, giờ chốt ngày hôm trước cho đạo cụ / in ấn)
 * nằm ở trang Cấu hình SLA: material.monthly_deadline, material.day_before_cutoff (config/sla.php).
 */
return [

    /** Còn dưới số giờ này tới hạn thì hiện "Sắp hết hạn". */
    'due_soon_hours' => 24,
];
