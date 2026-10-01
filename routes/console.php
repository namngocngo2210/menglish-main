<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('students:send-birthday-notifications')->dailyAt('08:00');
// Chăm sóc học viên tháng đầu: giao việc cho Học vụ (Buổi 1, Buổi 4–5, Đủ 30 ngày), đặt hạn khi tới mốc (idempotent).
Schedule::command('students:schedule-first-month-care')->hourlyAt(40);

Schedule::command('crm:scan-stale-leads')->hourly();
// Công việc / nhiệm vụ trợ giảng qua hạn (ngày + giờ hạn) → "Quá hạn"; việc chăm sóc tháng đầu quá SLA → biên bản vi phạm (idempotent).
Schedule::command('tasks:mark-overdue')->everyFifteenMinutes();

Schedule::command('tuition:send-debt-reminders')->dailyAt('08:30');

Schedule::command('bigtests:remind-upcoming')->dailyAt('07:45');

// SLA Big Test: phạt trả kết quả trễ (50.000đ/ngày), nhắc Học thuật duyệt đề mỗi ngày, việc duyệt đề, biên bản GV chưa nhận đề.
Schedule::command('bigtests:enforce-sla')->dailyAt('07:55');
// Cảnh báo hợp đồng nhân sự hết hạn trong 30 ngày (idempotent, chạy lại không tạo trùng).
Schedule::command('hr:notify-expiring-contracts')->dailyAt('07:50');
// Hết thời gian bảo lưu → học viên về Đang học / Chờ khai giảng, báo Học vụ (idempotent). Chạy trước nhắc nợ 08:30.
Schedule::command('students:end-deferrals')->dailyAt('06:50');
// Tới ngày bắt đầu bảo lưu đã duyệt trước → đóng băng số buổi / công nợ, học viên sang Bảo lưu (idempotent).
Schedule::command('students:start-deferrals')->dailyAt('06:45');
// Lớp tới ngày khai giảng → học viên Chờ khai giảng đã hoàn tất nhập học sang Đang học (idempotent). Chạy trước chăm sóc tháng đầu 07:40.
Schedule::command('students:start-studying')->dailyAt('06:55');
// SLA học phí: tiền mặt thu trong ngày phải nộp về TK công ty trước 19:00 → 19:05 nhắc phiếu chưa nộp (idempotent, không tự phạt).
Schedule::command('tuition:check-cash-deposits')->dailyAt('19:05');
// Hoàn phí / chuyển nhượng phải xử lý trong 1 tuần, cùng tháng → nhắc người duyệt khi còn ≤ 1 ngày hoặc quá hạn (idempotent theo ngày).
Schedule::command('tuition:notify-refund-deadlines')->dailyAt('08:40');
