<?php

namespace App\Http\Middleware;

use App\Support\Audit;
use App\Support\SensitiveData;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogBatch;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\Response;

class AuditOperationMiddleware
{
    /**
     * Mỗi request thay đổi dữ liệu (POST/PUT/PATCH/DELETE) được gom vào một
     * "batch" nhật ký. Nếu trong request đã có dòng nhật ký (model được audit
     * tự ghi trước/sau, hoặc controller gọi activity()), middleware KHÔNG ghi
     * thêm dòng chung nữa -> hết cảnh mỗi thao tác bị ghi 2 lần. Chỉ khi request
     * không sinh dòng nào, middleware mới ghi một dòng mô tả theo route.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $batch = app(LogBatch::class);
        $batch->startBatch();
        $batchUuid = $batch->getUuid();
        Audit::clearDescription();

        try {
            $response = $next($request);

            try {
                $alreadyLogged = $batchUuid && Activity::query()->where('batch_uuid', $batchUuid)->exists();
                if (! $alreadyLogged) {
                    $this->logActivity($request, $response);
                }
            } catch (\Throwable $e) {
                // Không bao giờ để lỗi ghi nhật ký làm hỏng luồng nghiệp vụ.
                report($e);
            }

            return $response;
        } finally {
            $batch->endBatch();
            Audit::clearDescription();
        }
    }

    protected function logActivity(Request $request, Response $response): void
    {
        $routeName = $request->route() ? $request->route()->getName() : null;
        $path = $request->path();
        $method = $request->method();

        // 1. Determine Module Name
        $module = $this->resolveModule($path, $routeName);

        // 2. Determine Action Event
        $event = match ($method) {
            'POST' => 'Tạo mới / Thao tác',
            'PUT', 'PATCH' => 'Cập nhật',
            'DELETE' => 'Xóa',
            default => 'Thao tác'
        };

        // 3. Generate Human-Readable Description
        $description = $this->generateDescription($request, $module, $event);

        // 4. Sanitize parameters (che mật khẩu, khóa bí mật, token... kể cả trong mảng lồng)
        $filteredParams = SensitiveData::mask($request->all());

        $properties = [
            'ip' => $request->ip(),
            'user_agent' => Str::limit($request->userAgent(), 255),
            'url' => SensitiveData::maskUrl($request->fullUrl()),
            'method' => $method,
            'route' => $routeName,
            'status_code' => $response->getStatusCode(),
            'payload' => $filteredParams,
        ];

        $activity = activity($module)
            ->event($event)
            ->withProperties($properties);

        if (Auth::check()) {
            $activity->causedBy(Auth::user());
        }

        $activity->log($description);
    }

    protected function resolveModule(string $path, ?string $routeName): string
    {
        if ($routeName) {
            if (str_starts_with($routeName, 'crm.')) return 'CRM & Leads';
            if (str_starts_with($routeName, 'tuition.') || str_starts_with($routeName, 'receipts.')) return 'Học phí & Thu chi';
            if (str_starts_with($routeName, 'students.') || str_starts_with($routeName, 'student-classes.')) return 'Học viên & Lớp học';
            if (str_starts_with($routeName, 'placement-tests.') || str_starts_with($routeName, 'portal.test.')) return 'Khảo sát & Đề thi';
            if (str_starts_with($routeName, 'syllabi.') || str_starts_with($routeName, 'units.')) return 'Giáo trình & Syllabus';
            if (str_starts_with($routeName, 'payroll.') || str_starts_with($routeName, 'timesheets.')) return 'Bảng lương & Chấm công';
            if (str_starts_with($routeName, 'tasks.') || str_starts_with($routeName, 'ta-tasks.')) return 'Quản lý công việc';
            if (str_starts_with($routeName, 'tickets.')) return 'Ticket hỗ trợ';
            if (str_starts_with($routeName, 'media.')) return 'Quản lý Media';
            if (str_starts_with($routeName, 'system-configs.') || str_starts_with($routeName, 'system-categories.')) return 'Cấu hình hệ thống';
            if (str_starts_with($routeName, 'users.') || str_starts_with($routeName, 'roles.') || str_starts_with($routeName, 'permissions.')) return 'Người dùng & Phân quyền';
            if (str_starts_with($routeName, 'profile.')) return 'Tài khoản & Hồ sơ';
        }

        // Fallback matching by URL path
        if (str_contains($path, 'crm') || str_contains($path, 'customer')) return 'CRM & Leads';
        if (str_contains($path, 'tuition') || str_contains($path, 'receipt') || str_contains($path, 'invoice') || str_contains($path, 'refund')) return 'Học phí & Thu chi';
        if (str_contains($path, 'student') || str_contains($path, 'class')) return 'Học viên & Lớp học';
        if (str_contains($path, 'placement-test') || str_contains($path, 'test')) return 'Khảo sát & Đề thi';
        if (str_contains($path, 'syllab')) return 'Giáo trình & Syllabus';
        if (str_contains($path, 'payroll') || str_contains($path, 'salary') || str_contains($path, 'timesheet')) return 'Bảng lương & Chấm công';
        if (str_contains($path, 'task')) return 'Quản lý công việc';
        if (str_contains($path, 'ticket')) return 'Ticket hỗ trợ';
        if (str_contains($path, 'media')) return 'Quản lý Media';
        if (str_contains($path, 'system') || str_contains($path, 'setting') || str_contains($path, 'bank')) return 'Cấu hình hệ thống';
        if (str_contains($path, 'user') || str_contains($path, 'role') || str_contains($path, 'permission')) return 'Người dùng & Phân quyền';

        return 'Hệ thống chung';
    }

    protected function generateDescription(Request $request, string $module, string $event): string
    {
        $routeName = $request->route() ? $request->route()->getName() : '';
        $method = $request->method();

        // Specific tailored descriptions
        if ($routeName === 'crm.closing.complete') {
            $name = $request->input('customer_name') ?? 'Lead CRM';
            $paid = number_format((int)$request->input('paid_amount', 0));
            return "Chốt deal & xếp lớp cho {$name} (Đóng {$paid}đ)";
        }
        if ($routeName === 'crm.stage.update') {
            return "Chuyển trạng thái Pipeline sang: " . ($request->input('stage') ?? 'mới');
        }
        if ($routeName === 'crm.notes.store') {
            return "Thêm nhật ký chăm sóc Lead: " . Str::limit((string)$request->input('content', ''), 60);
        }
        if ($routeName === 'crm.store') {
            return "Tạo khách hàng tiềm năng mới: " . ($request->input('name') ?? '');
        }
        if ($routeName === 'tuition.receipts.store') {
            $amount = number_format((int)$request->input('amount', 0));
            return "Tạo phiếu thu học phí: {$amount}đ";
        }
        if ($routeName === 'tuition.receipts.approve') {
            return "Phê duyệt phiếu thu học phí #" . $request->route('id');
        }
        if ($routeName === 'tuition.receipts.reject') {
            return "Từ chối duyệt phiếu thu học phí #" . $request->route('id');
        }
        if ($routeName === 'tuition.transfer') {
            $sessions = $request->input('sessions', 0);
            return "Chuyển nhượng {$sessions} buổi học phí thừa sang học viên khác";
        }
        if ($routeName === 'tuition.refunds.store') {
            return "Tạo yêu cầu hoàn phí / bảo lưu học phí cho học viên";
        }
        if ($routeName === 'placement-tests.store') {
            return "Tạo bộ đề kiểm tra đầu vào: " . ($request->input('title') ?? '');
        }
        if ($routeName === 'placement-tests.update') {
            return "Cập nhật cấu hình & câu hỏi đề thi #" . $request->route('id');
        }
        if ($routeName === 'placement-tests.duplicate') {
            return "Nhân bản đề thi chuẩn hóa #" . $request->route('id');
        }
        if ($routeName === 'placement-tests.results.update') {
            return "Giáo viên chấm điểm & nhận xét bài thi #" . $request->route('id');
        }
        if ($routeName === 'portal.test.submit') {
            $candidate = $request->input('candidate_name', 'Thí sinh');
            return "Thí sinh {$candidate} nộp bài thi trực tuyến và tự động chấm điểm";
        }
        if ($routeName === 'students.store') {
            return "Tạo hồ sơ học viên mới: " . ($request->input('name') ?? '');
        }
        if ($routeName === 'students.enroll') {
            return "Xếp lớp học viên vào lớp #" . ($request->input('class_id') ?? '');
        }
        if ($routeName === 'tasks.store') {
            return "Tạo & giao nhiệm vụ công việc mới: " . ($request->input('title') ?? '');
        }
        if ($routeName === 'tasks.ta.assign') {
            return "Phân công nhiệm vụ Trợ giảng (TA Task)";
        }
        if ($routeName === 'tickets.store') {
            return "Tạo Ticket yêu cầu hỗ trợ: " . ($request->input('title') ?? $request->input('subject') ?? '');
        }
        if ($routeName === 'tickets.messages.store') {
            return "Gửi tin nhắn phản hồi trong Ticket #" . $request->route('ticket');
        }
        if ($routeName === 'media.upload') {
            return "Tải lên tệp tin tài liệu / hình ảnh mới";
        }
        if ($routeName === 'media.folder.create') {
            return "Tạo thư mục media mới: " . ($request->input('folder_name') ?? '');
        }
        if ($routeName === 'media.destroy') {
            return "Xóa tệp tin khỏi thư mục lưu trữ media";
        }
        if ($routeName === 'payroll.periods.store') {
            return "Khởi tạo kỳ tính lương: " . ($request->input('name') ?? '');
        }
        if ($routeName === 'payroll.periods.approve') {
            return "Phê duyệt & chốt bảng lương kỳ #" . $request->route('id');
        }
        if ($routeName === 'payroll.timesheets.store') {
            return "Ghi nhận giờ dạy / chấm công giáo viên";
        }
        if ($routeName === 'system-configs.banks.store') {
            return "Cấu hình tài khoản ngân hàng chuyển khoản học phí";
        }

        // Generic fallback
        $target = $request->input('name') ?? $request->input('title') ?? $request->input('code') ?? '';
        if ($target) {
            return "{$event} {$module} ({$target})";
        }

        return "{$event} dữ liệu thuộc phân hệ {$module}";
    }
}
