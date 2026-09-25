<?php

namespace App\Console\Commands;

use App\Models\AcademicRecord;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendBirthdayNotificationsCommand extends Command
{
    protected $signature = 'students:send-birthday-notifications {--date= : Ngày xử lý Y-m-d}';

    protected $description = 'Tạo thông báo sinh nhật đúng ngày cho học viên';

    public function handle(): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now();
        $students = Student::whereNotNull('dob')
            ->whereMonth('dob', $date->month)
            ->whereDay('dob', $date->day)
            ->get();

        $created = 0;
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
        }

        $this->info("Đã tạo {$created} thông báo sinh nhật.");

        return self::SUCCESS;
    }
}
