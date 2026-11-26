<?php

return [
    // Số ngày phía trước mà job classes:generate-sessions đảm bảo lớp đang học đã có buổi.
    'session_horizon_days' => (int) env('SESSION_GENERATE_HORIZON_DAYS', 28),
];
