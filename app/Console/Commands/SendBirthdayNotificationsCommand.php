<?php

namespace App\Console\Commands;

use App\Models\AcademicRecord;
use App\Models\AdminNotification;
use App\Models\ClassModel;
use App\Models\Student;
use App\Services\BranchStaff;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendBirthdayNotificationsCommand extends Command
{
    protected $signature = 'students:send-birthday-notifications {--date= : Ngày xử lý Y-m-d}';

    protected $description = 'Tạo thông báo sinh nhật đúng ngày cho học viên và nhắc GV / Học vụ của lớp';

    /** Học viên đã thôi học / hoàn thành khóa không nhận chúc mừng sinh nhật. */
    private const EXCLUDED_STATUSES = ['dropped', 'completed'];

    public function handle(): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        $students = Student::with('currentClass')
            ->whereNotNull('dob')
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->whereMonth('dob', $date->month)
            ->whereDay('dob', $date->day)
            ->get();

        $created = 0;
        $alerts = 0;
        foreach ($students as $student) {
            $code = 'BIRTHDAY-'.$date->year.'-'.$student->id;
            $record = AcademicRecord::firstOrCreate(
                ['screen_key' => '04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao', 'record_code' => $code],
                [
                    'module' => 'student_portal',
                    'title' => 'Chúc mừng sinh nhật!',
                    'status' => 'active',
                    'data' => [
                        'type' => 'birthday',
                        'title' => 'Chúc mừng sinh nhật!',
                        'content' => 'Trung tâm gửi lời chúc mừng sinh nhật tốt đẹp nhất đến học viên '.$student->name.'.',
                        'unread' => true,
                        'icon' => 'cake',
                        'bg_color' => 'bg-blue-100',
                        'text_color' => 'text-blue-600',
                        'created_at' => $date->format('d/m/Y H:i'),
                        'student_id' => (string) $student->id,
                        'student_name' => $student->name,
                    ],
                    'user_id' => $student->user_id,
                ]
            );
            $created += $record->wasRecentlyCreated ? 1 : 0;
            $alerts += $this->alertStaff($student, $date);
        }

        $this->info("Đã tạo {$created} thông báo sinh nhật, {$alerts} nhắc nhở cho giáo viên / Học vụ.");

        return self::SUCCESS;
    }

    /**
     * Nhắc giáo viên (GV / GVNN / trợ giảng) các lớp học viên đang học và Học vụ chi nhánh để
     * chúc mừng học viên. Mỗi người nhận 1 nhắc nhở cho mỗi học viên mỗi năm.
     */
    private function alertStaff(Student $student, Carbon $date): int
    {
        $classes = ClassModel::whereIn('id', $student->activeClassIds())->get();
        $recipientIds = $classes->flatMap(fn (ClassModel $c) => [$c->teacher_id, $c->foreign_teacher_id, $c->assistant_id])
            ->merge(BranchStaff::academicStaff($student->branch_id ?: $student->currentClass?->branch_id)->pluck('id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        $count = 0;
        foreach ($recipientIds as $userId) {
            $exists = AdminNotification::where('type', 'student_birthday')
                ->where('user_id', $userId)
                ->where('data->student_id', $student->id)
                ->where('data->year', $date->year)
                ->exists();
            if ($exists) {
                continue;
            }

            AdminNotification::create([
                'user_id' => $userId,
                'type' => 'student_birthday',
                'title' => 'Sinh nhật học viên '.$student->name,
                'message' => 'Hôm nay ('.$date->format('d/m').') là sinh nhật học viên '.$student->name
                    .($classes->isNotEmpty() ? ' — lớp '.$classes->pluck('name')->implode(', ') : '').'. Nhớ gửi lời chúc mừng nhé!',
                'data' => [
                    'student_id' => $student->id,
                    'year' => $date->year,
                    'link' => route('students.show', $student->id),
                ],
                'is_read' => false,
            ]);
            $count++;
        }

        return $count;
    }
}
