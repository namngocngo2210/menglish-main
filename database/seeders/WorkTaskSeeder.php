<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\ClassReport;
use App\Models\ClassReportStudentSupport;
use App\Models\ClassScheduleConfig;
use App\Models\HrDailyDemand;
use App\Models\Student;
use App\Models\User;
use App\Models\WorkTask;
use Illuminate\Database\Seeder;

class WorkTaskSeeder extends Seeder
{
    public function run(): void
    {
        $branchCG = Branch::where('code', 'CG')->first() ?? Branch::first();
        $admin = User::where('email', 'admin@menglish.edu.vn')->first() ?? User::first();
        
        \Spatie\Permission\Models\Role::findOrCreate('assistant', 'web');
        
        // Tạo thêm các TA (Trợ giảng) nếu chưa có
        $ta1 = User::firstOrCreate(
            ['email' => 'ta.tuan@menglish.edu.vn'],
            ['name' => 'Trần Anh Tuấn', 'phone' => '0912000001', 'branch_id' => $branchCG?->id, 'password' => bcrypt('Password123!'), 'is_active' => true]
        );
        $ta1->syncRoles(['assistant']);

        $ta2 = User::firstOrCreate(
            ['email' => 'ta.tram@menglish.edu.vn'],
            ['name' => 'Mai Ngọc Trâm', 'phone' => '0912000002', 'branch_id' => $branchCG?->id, 'password' => bcrypt('Password123!'), 'is_active' => true]
        );
        $ta2->syncRoles(['assistant']);

        $ta3 = User::firstOrCreate(
            ['email' => 'ta.yen@menglish.edu.vn'],
            ['name' => 'Lê Hải Yến', 'phone' => '0912000003', 'branch_id' => $branchCG?->id, 'password' => bcrypt('Password123!'), 'is_active' => true]
        );
        $ta3->syncRoles(['assistant']);

        $ta4 = User::firstOrCreate(
            ['email' => 'ta.thu@menglish.edu.vn'],
            ['name' => 'Phạm Thị Thu', 'phone' => '0912000004', 'branch_id' => $branchCG?->id, 'password' => bcrypt('Password123!'), 'is_active' => true]
        );
        $ta4->syncRoles(['assistant']);

        $ta5 = User::firstOrCreate(
            ['email' => 'ta.hai@menglish.edu.vn'],
            ['name' => 'Hoàng Văn Hải', 'phone' => '0912000005', 'branch_id' => $branchCG?->id, 'password' => bcrypt('Password123!'), 'is_active' => true]
        );
        $ta5->syncRoles(['assistant']);

        $ta6 = User::firstOrCreate(
            ['email' => 'ta.linh@menglish.edu.vn'],
            ['name' => 'Nguyễn Thu Linh', 'phone' => '0912000006', 'branch_id' => $branchCG?->id, 'password' => bcrypt('Password123!'), 'is_active' => true]
        );
        $ta6->syncRoles(['assistant']);

        $classIE = ClassModel::where('code', 'IE-2408')->first();
        $classGT = ClassModel::where('code', 'GT-08')->first();

        // 1. Seed danh sách công việc chung & phân công
        $tasks = [
            [
                'title' => 'Cập nhật báo cáo tài chính Q3',
                'description' => 'Dự án Alpha - Tổng hợp số liệu thu chi từ các cơ sở',
                'creator_id' => $admin->id,
                'assignee_id' => $ta1->id,
                'branch_id' => $branchCG?->id,
                'task_type' => 'one_time',
                'time_slot_category' => 'before',
                'due_date' => now()->subDays(5)->toDateString(),
                'status' => 'overdue',
            ],
            [
                'title' => 'Tích hợp API thanh toán',
                'description' => 'Tích hợp cổng thanh toán VietQR Pro cho hệ thống thu phí',
                'creator_id' => $admin->id,
                'assignee_id' => $ta2->id,
                'branch_id' => $branchCG?->id,
                'task_type' => 'one_time',
                'time_slot_category' => 'during',
                'due_date' => now()->addDays(6)->toDateString(),
                'status' => 'blocked',
                'blocked_reason' => 'Đang chờ đối tác ngân hàng cấp Client Secret và Webhook Endpoint',
            ],
            [
                'title' => 'Thiết kế landing page chiến dịch Thu',
                'description' => 'Chiến dịch tuyển sinh mùa Thu 2026 - Khóa IELTS Bứt Phá',
                'creator_id' => $admin->id,
                'assignee_id' => $ta3->id,
                'branch_id' => $branchCG?->id,
                'task_type' => 'one_time',
                'time_slot_category' => 'during',
                'due_date' => now()->addDays(9)->toDateString(),
                'status' => 'in_progress',
            ],
            [
                'title' => 'Kiểm kê kho bãi & giáo trình tháng 10',
                'description' => 'Kiểm đếm sách Student Book & Workbook tại kho Cầu Giấy',
                'creator_id' => $admin->id,
                'assignee_id' => $ta4->id,
                'branch_id' => $branchCG?->id,
                'task_type' => 'recurring',
                'frequency' => 'monthly',
                'time_slot_category' => 'after',
                'due_date' => now()->addDays(12)->toDateString(),
                'status' => 'new',
            ],
            [
                'title' => 'Gửi email thông báo nội bộ lịch nghỉ lễ',
                'description' => 'Gửi thông báo lịch nghỉ Quốc khánh đến toàn thể cán bộ GV/NV',
                'creator_id' => $admin->id,
                'assignee_id' => $ta1->id,
                'branch_id' => $branchCG?->id,
                'task_type' => 'recurring',
                'frequency' => 'weekly',
                'time_slot_category' => 'after',
                'due_date' => now()->subDays(2)->toDateString(),
                'status' => 'completed',
                'completed_at' => now()->subDays(2),
            ],
            [
                'title' => 'Lên lịch họp hội đồng quản trị',
                'description' => 'Chuẩn bị phòng họp và tài liệu chiến lược Q4',
                'creator_id' => $admin->id,
                'assignee_id' => $ta5->id,
                'branch_id' => $branchCG?->id,
                'task_type' => 'one_time',
                'time_slot_category' => 'before',
                'due_date' => now()->addDays(1)->toDateString(),
                'status' => 'canceled',
            ],
            // Nhiệm vụ hôm nay của TA (Trần Anh Tuấn)
            [
                'title' => 'Chuẩn bị tài liệu in ấn cho khối lớp 4',
                'description' => 'In 25 bộ bài tập Listening Unit 5 & phát trước 15 phút',
                'creator_id' => $admin->id,
                'assignee_id' => $ta1->id,
                'branch_id' => $branchCG?->id,
                'task_type' => 'one_time',
                'time_slot_category' => 'before',
                'due_date' => now()->toDateString(),
                'due_time' => '14:00',
                'status' => 'new',
            ],
            [
                'title' => 'Kiểm tra trang thiết bị phòng học',
                'description' => 'Kiểm tra máy chiếu, mic trợ giảng, loa và điều hòa phòng 101',
                'creator_id' => $admin->id,
                'assignee_id' => $ta1->id,
                'branch_id' => $branchCG?->id,
                'class_id' => $classIE?->id,
                'lesson_session' => 'Buổi 5 - Listening Practice',
                'task_type' => 'one_time',
                'time_slot_category' => 'before',
                'due_date' => now()->toDateString(),
                'due_time' => '15:30',
                'status' => 'in_progress',
            ],
            [
                'title' => 'Hỗ trợ GVNN điều phối trò chơi',
                'description' => 'Hỗ trợ thầy John điều phối hoạt động Warm-up và chia nhóm thảo luận',
                'creator_id' => $admin->id,
                'assignee_id' => $ta1->id,
                'branch_id' => $branchCG?->id,
                'task_type' => 'one_time',
                'time_slot_category' => 'during',
                'due_date' => now()->toDateString(),
                'due_time' => '16:00',
                'status' => 'pending_confirmation',
                'completion_note' => 'Đã hỗ trợ xong phần warm-up và chia nhóm 4 bạn/đội',
            ],
            [
                'title' => 'Chấm điểm bài kiểm tra nhanh',
                'description' => 'Chấm bài Quick Test 15 phút ngữ pháp của lớp GT-08',
                'creator_id' => $admin->id,
                'assignee_id' => $ta1->id,
                'branch_id' => $branchCG?->id,
                'class_id' => $classGT?->id,
                'lesson_session' => 'Buổi 5 - Speaking & Pronunciation',
                'task_type' => 'one_time',
                'time_slot_category' => 'during',
                'due_date' => now()->toDateString(),
                'due_time' => '17:00',
                'status' => 'blocked',
                'blocked_reason' => 'Thiếu đề bài và rubric chấm điểm từ GV chính',
            ],
            [
                'title' => 'Dọn dẹp phòng học & tắt thiết bị điện',
                'description' => 'Kiểm tra tắt máy chiếu, máy lạnh, khóa cửa phòng 101',
                'creator_id' => $admin->id,
                'assignee_id' => $ta1->id,
                'branch_id' => $branchCG?->id,
                'task_type' => 'recurring',
                'frequency' => 'daily',
                'time_slot_category' => 'after',
                'due_date' => now()->toDateString(),
                'due_time' => '18:00',
                'status' => 'completed',
                'completed_at' => now()->subHours(2),
                'completion_note' => 'Đã khóa cửa và bàn giao chìa khóa cho bảo vệ',
            ],
            [
                'title' => 'Cập nhật điểm danh lên hệ thống',
                'description' => 'Nhập điểm danh sĩ số lớp học lên hệ thống MEnglish LMS',
                'creator_id' => $admin->id,
                'assignee_id' => $ta1->id,
                'branch_id' => $branchCG?->id,
                'task_type' => 'recurring',
                'frequency' => 'daily',
                'time_slot_category' => 'after',
                'due_date' => now()->toDateString(),
                'due_time' => '12:00',
                'status' => 'overdue',
            ],
            // Nhiệm vụ chờ xác nhận thủ công (Manual approvals)
            [
                'title' => 'Chấm bài tập về nhà Unit 5',
                'description' => 'Chấm 18 bài tập về nhà kỹ năng Viết & Đọc hiểu',
                'creator_id' => $admin->id,
                'assignee_id' => $ta1->id,
                'branch_id' => $branchCG?->id,
                'class_id' => $classIE?->id,
                'lesson_session' => 'Buổi 5 - Listening & Writing',
                'task_type' => 'one_time',
                'time_slot_category' => 'during',
                'due_date' => now()->addDays(1)->toDateString(),
                'due_time' => '23:59',
                'status' => 'pending_confirmation',
                'completion_note' => "Em đã chấm xong bài tập của 15/18 bạn nộp. 3 bạn chưa nộp em đã liên hệ nhắc nhở. File điểm chi tiết em đã cập nhật lên Google Drive chung của lớp ạ.\nLink: https://drive.google.com/drive/folders/menglish-k24-unit5",
            ],
            [
                'title' => 'Hỗ trợ học viên yếu (Kèm riêng)',
                'description' => 'Kèm riêng 30 phút phát âm cho 2 bạn học viên mới',
                'creator_id' => $admin->id,
                'assignee_id' => $ta2->id,
                'branch_id' => $branchCG?->id,
                'class_id' => $classGT?->id,
                'lesson_session' => 'Buổi 4 - Vowel Sounds',
                'task_type' => 'one_time',
                'time_slot_category' => 'after',
                'due_date' => now()->subDays(1)->toDateString(),
                'due_time' => '21:00',
                'status' => 'pending_confirmation',
                'completion_note' => 'Đã hỗ trợ bạn Trọng và bạn Sơn sửa âm /θ/ và /ð/',
            ],
            [
                'title' => 'Soạn tài liệu ôn tập giữa kỳ',
                'description' => 'Tổng hợp ngữ pháp và từ vựng Unit 1 đến Unit 4',
                'creator_id' => $admin->id,
                'assignee_id' => $ta3->id,
                'branch_id' => $branchCG?->id,
                'task_type' => 'one_time',
                'time_slot_category' => 'before',
                'due_date' => now()->subDays(2)->toDateString(),
                'due_time' => '17:00',
                'status' => 'pending_confirmation',
                'completion_note' => 'Đã hoàn thành file tóm tắt 8 trang và chuyển cho trưởng ban học thuật duyệt.',
            ],
        ];

        foreach ($tasks as $t) {
            // Đánh dấu dữ liệu seed bằng tiền tố "# "
            $t['title'] = '# ' . $t['title'];
            // Idempotent: chạy lại db:seed không nhân bản việc mẫu.
            WorkTask::firstOrCreate(['title' => $t['title'], 'assignee_id' => $t['assignee_id']], $t);
        }

        // 2. Seed Báo cáo trực lớp (ClassReport & StudentSupport)
        if ($classIE) {
            $student = Student::where('code', 'HV-00103')->first() ?? Student::first();
            $report = ClassReport::firstOrCreate([
                'class_id' => $classIE->id,
                'reporter_id' => $ta1->id,
                'session_name' => 'Buổi 5 - Listening Practice',
            ], [
                'session_date' => now()->toDateString(),
                'topics_learned' => 'Hôm nay học Section 1 & Section 2 dạng bài Form/Note Completion, chiến thuật bắt từ khóa (Keywords) và tránh bẫy ngữ pháp.',
                'teaching_log' => 'Lớp học nghiêm túc, phần nghe số điện thoại và tên riêng còn một số bạn nhầm lẫn giữa 15 và 50.',
                'board_image' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuBFTD6fQIxADmocyShINzv7KOvb1ALGY2F9dnYVLI_AbBW4j1OKmBf7HfSRv19dwBM0abWYiceEHVvf0gPRhoqnB6jRDu7HStZDfzN8TKNInOyAT-AFeu1ZOVlMCaY9fdnRfzCA9AhhED_Smh7D5UAhokik0gKxP1wLGBosGhIfH0QrFlNmLmhB3dpL2P_l-n3s0FgJvl5VRi3bG6Cp8bO8BUGJ9mEpFk_AuJPJFg-9vQx6xClOlRn8',
                'has_image' => true,
                'status' => 'approved',
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]);

            if ($student) {
                ClassReportStudentSupport::firstOrCreate([
                    'class_report_id' => $report->id,
                    'student_id' => $student->id,
                ], [
                    'absence_session' => 'Buổi 3 - Speaking',
                    'reason' => 'Học sinh yếu kỹ năng nghe, không theo kịp tiến độ trên lớp.',
                    'action_plan' => 'Làm lại bài tập nghe Part 1 trang 12 và ghi âm gửi TA.',
                ]);
            }
        }

        // 3. Seed Cấu hình lịch lớp (ClassScheduleConfig)
        if ($classIE) {
            ClassScheduleConfig::updateOrCreate(
                ['class_id' => $classIE->id],
                [
                    'academic_year' => 'Năm học 2024 - 2025',
                    'slot1_day' => 'Thứ 5',
                    'slot1_start' => '18:00',
                    'slot1_end' => '19:30',
                    'slot2_day' => 'Thứ 7',
                    'slot2_start' => '18:00',
                    'slot2_end' => '19:30',
                ]
            );
        }
        if ($classGT) {
            ClassScheduleConfig::updateOrCreate(
                ['class_id' => $classGT->id],
                [
                    'academic_year' => 'Năm học 2024 - 2025',
                    'slot1_day' => 'Thứ 3',
                    'slot1_start' => '19:30',
                    'slot1_end' => '21:30',
                    'slot2_day' => 'Thứ 6',
                    'slot2_start' => '19:30',
                    'slot2_end' => '21:30',
                ]
            );
        }

        // 4. Seed Báo cáo phòng / nhân sự (HrDailyDemand)
        $days = [
            ['day' => 'Thứ 2', 'date' => now()->startOfWeek()->toDateString(), 'shifts' => 8, 'staff' => 4],
            ['day' => 'Thứ 3', 'date' => now()->startOfWeek()->addDays(1)->toDateString(), 'shifts' => 6, 'staff' => 3],
            ['day' => 'Thứ 4', 'date' => now()->startOfWeek()->addDays(2)->toDateString(), 'shifts' => 8, 'staff' => 4],
            ['day' => 'Thứ 5', 'date' => now()->startOfWeek()->addDays(3)->toDateString(), 'shifts' => 6, 'staff' => 3],
            ['day' => 'Thứ 6', 'date' => now()->startOfWeek()->addDays(4)->toDateString(), 'shifts' => 8, 'staff' => 4],
            ['day' => 'Thứ 7', 'date' => now()->startOfWeek()->addDays(5)->toDateString(), 'shifts' => 10, 'staff' => 5],
            ['day' => 'Chủ nhật', 'date' => now()->startOfWeek()->addDays(6)->toDateString(), 'shifts' => 10, 'staff' => 5],
        ];

        foreach ($days as $d) {
            // report_date lưu dạng datetime trên SQLite: so theo ngày để chạy lại không nhân bản.
            $demand = HrDailyDemand::where('branch_id', $branchCG?->id)->whereDate('report_date', $d['date'])->first()
                ?? new HrDailyDemand(['branch_id' => $branchCG?->id, 'report_date' => $d['date']]);
            $demand->fill([
                'day_of_week' => $d['day'],
                'shift_count' => $d['shifts'],
                'staff_needed' => $d['staff'],
            ])->save();
        }
    }
}
