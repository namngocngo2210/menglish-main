<?php

namespace App\Services\Tuition;

use App\Models\AcademicRecord;
use App\Models\Student;
use App\Models\User;
use App\Support\Money;

/**
 * "Báo đóng học phí" do học viên / phụ huynh gửi từ cổng học viên (lưu ở academic_records). Kế toán xác nhận
 * (đã nhận tiền, sẽ lập phiếu thu) hoặc từ chối trong hộp "Cần duyệt"; học viên nhận thông báo kết quả ở hộp thư cổng.
 */
class PaymentReportService
{
    public const SCREEN_KEY = '04_Cong_Phu_Huynh_Hoc_Sinh/02_trang_chu_phu_huynh_hoc_sinh';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    private const NOTIFICATION_SCREEN_KEY = '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao';

    public function confirm(AcademicRecord $report, User $user): void
    {
        $this->resolve($report, $user, self::STATUS_CONFIRMED, null);
    }

    public function reject(AcademicRecord $report, User $user, string $reason): void
    {
        $this->resolve($report, $user, self::STATUS_REJECTED, $reason);
    }

    private function resolve(AcademicRecord $report, User $user, string $status, ?string $reason): void
    {
        $data = $report->data ?? [];
        $report->update([
            'status' => $status,
            'data' => array_merge($data, array_filter([
                'resolved_by' => $user->name,
                'resolved_at' => now()->format('d/m/Y H:i'),
                'rejection_reason' => $reason,
            ])),
        ]);

        $student = Student::find(data_get($data, 'student_id'));
        if (! $student) {
            return;
        }

        $amount = Money::format((float) data_get($data, 'amount', 0));
        $confirmed = $status === self::STATUS_CONFIRMED;

        AcademicRecord::create([
            'screen_key' => self::NOTIFICATION_SCREEN_KEY,
            'module' => 'student_portal',
            'record_code' => 'YCHOCPHI-KQ-'.$report->id,
            'title' => ($confirmed ? 'Kế toán đã xác nhận khoản đóng ' : 'Kế toán chưa xác nhận khoản đóng ').$amount,
            'status' => 'active',
            'data' => [
                'type' => 'tuition_payment_report',
                'title' => $confirmed ? 'Đã xác nhận đóng học phí' : 'Chưa xác nhận đóng học phí',
                'content' => $confirmed
                    ? 'Kế toán đã xác nhận khoản đóng '.$amount.' của học viên '.$student->name.'. Biên lai sẽ hiện trong lịch sử thu học phí sau khi phiếu thu được duyệt.'
                    : 'Kế toán chưa xác nhận khoản đóng '.$amount.' của học viên '.$student->name.'. Lý do: '.$reason.'. Vui lòng liên hệ trung tâm để được hỗ trợ.',
                'unread' => true,
                'icon' => $confirmed ? 'task_alt' : 'error',
                'bg_color' => $confirmed ? 'bg-tertiary/10' : 'bg-error-container',
                'text_color' => $confirmed ? 'text-tertiary' : 'text-error',
                'student_id' => (string) $student->id,
                'created_at' => now()->toDateTimeString(),
            ],
            'user_id' => $student->user_id,
        ]);
    }
}
