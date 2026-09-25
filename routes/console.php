<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('students:send-birthday-notifications')->dailyAt('08:00');
// Chăm sóc học viên tháng đầu: việc cho Học vụ ở ngày 3/7/14/30 (idempotent).
Schedule::command('students:schedule-first-month-care')->dailyAt('07:40');

Schedule::command('crm:scan-stale-leads')->hourly();

Schedule::command('tuition:send-debt-reminders')->dailyAt('08:30');

Schedule::command('bigtests:remind-upcoming')->dailyAt('07:45');
// Cảnh báo hợp đồng nhân sự hết hạn trong 30 ngày (idempotent, chạy lại không tạo trùng).
Schedule::command('hr:notify-expiring-contracts')->dailyAt('07:50');
// Hết thời gian bảo lưu → học viên về Đang học / Chờ khai giảng, báo Học vụ (idempotent). Chạy trước nhắc nợ 08:30.
Schedule::command('students:end-deferrals')->dailyAt('06:50');
