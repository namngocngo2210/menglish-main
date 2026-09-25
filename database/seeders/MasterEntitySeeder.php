<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\BigTest;
use App\Models\BigTestResult;
use App\Models\Branch;
use App\Models\ClassEnrollment;
use App\Models\ClassModel;
use App\Models\CommissionTier;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\DebtReminderRule;
use App\Models\InvoiceConfiguration;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Penalty;
use App\Models\PlacementTest;
use App\Models\PlacementTestSubmission;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\SyllabusAssignment;
use App\Models\SyllabusCurriculum;
use App\Models\SyllabusUnit;
use App\Models\TeacherRate;
use App\Models\TeacherTimesheet;
use App\Models\TuitionReceipt;
use App\Models\User;
use Illuminate\Database\Seeder;

class MasterEntitySeeder extends Seeder
{
    public function run(): void
    {
        $branchCG = Branch::firstOrCreate(['code' => 'CG'], ['name' => 'Cơ sở 1 - Cầu Giấy', 'address' => 'Hà Nội', 'phone' => '0900000001', 'is_active' => true]);
        $branchBD = Branch::firstOrCreate(['code' => 'BD'], ['name' => 'Cơ sở 2 - Ba Đình', 'address' => 'Hà Nội', 'phone' => '0900000002', 'is_active' => true]);
        $branchDD = Branch::firstOrCreate(['code' => 'DD'], ['name' => 'Cơ sở 3 - Đống Đa', 'address' => 'Hà Nội', 'phone' => '0900000003', 'is_active' => true]);
        $branchHBT = Branch::firstOrCreate(['code' => 'HBT'], ['name' => 'Cơ sở 4 - Hai Bà Trưng', 'address' => 'Hà Nội', 'phone' => '0900000004', 'is_active' => true]);

        $admin = User::firstOrCreate(['email' => 'admin@menglish.edu.vn'], ['name' => 'Admin Hệ thống', 'password' => bcrypt('Password123!'), 'is_active' => true, 'branch_id' => $branchCG->id]);
        $teacher = User::firstOrCreate(['email' => 'nguyenvanan@menglish.edu.vn'], ['name' => 'ThS. Nguyễn Quốc Anh', 'password' => bcrypt('Password123!'), 'is_active' => true, 'branch_id' => $branchCG->id]);
        $academic = User::firstOrCreate(['email' => 'nva@menglish.edu.vn'], ['name' => 'Đặng Hồng Nhung', 'password' => bcrypt('Password123!'), 'is_active' => true, 'branch_id' => $branchCG->id]);
        $accountant = User::firstOrCreate(['email' => 'ttb@menglish.edu.vn'], ['name' => 'Trần Thị B', 'password' => bcrypt('Password123!'), 'is_active' => true, 'branch_id' => $branchBD->id]);

        // 1. Khung trình độ
        $levelsData = [
            ['code' => 'A1', 'name' => 'Beginner (Mất gốc)', 'target' => 'CEFR A1 / IELTS 3.0', 'duration' => '8 tuần / 16 buổi', 'lessons_count' => 16],
            ['code' => 'A2', 'name' => 'Elementary (Cơ bản)', 'target' => 'CEFR A2 / IELTS 4.0', 'duration' => '10 tuần / 20 buổi', 'lessons_count' => 20],
            ['code' => 'B1', 'name' => 'Intermediate (Trung cấp)', 'target' => 'CEFR B1 / IELTS 5.5', 'duration' => '12 tuần / 24 buổi', 'lessons_count' => 24],
            ['code' => 'B2', 'name' => 'Upper-Intermediate (Chuyên sâu)', 'target' => 'CEFR B2 / IELTS 6.5', 'duration' => '12 tuần / 24 buổi', 'lessons_count' => 24],
            ['code' => 'C1', 'name' => 'Advanced (Cao cấp / Master)', 'target' => 'CEFR C1 / IELTS 7.5+', 'duration' => '14 tuần / 28 buổi', 'lessons_count' => 28],
        ];
        foreach ($levelsData as $ld) {
            CourseLevel::query()->updateOrCreate(['code' => $ld['code']], $ld + ['is_active' => true]);
        }
        $levelB1 = CourseLevel::where('code', 'B1')->first();
        $levelB2 = CourseLevel::where('code', 'B2')->first();
        $levelC1 = CourseLevel::where('code', 'C1')->first();

        // 2. Khóa học
        $coursesData = [
            ['code' => 'IE-65', 'name' => 'IELTS 6.5 Intensive', 'course_level_id' => $levelB2?->id, 'tuition_fee' => 12500000, 'total_lessons' => 24, 'description' => 'Khóa luyện thi IELTS chuyên sâu 4 kỹ năng cam kết đầu ra 6.5+'],
            ['code' => 'IE-70', 'name' => 'IELTS 7.0 Master', 'course_level_id' => $levelC1?->id, 'tuition_fee' => 16500000, 'total_lessons' => 28, 'description' => 'Khóa bứt phá band 7.0 - 8.0 chuyên sâu Writing & Speaking Task 2'],
            ['code' => 'GT-B1', 'name' => 'Giao tiếp Pro B1', 'course_level_id' => $levelB1?->id, 'tuition_fee' => 9500000, 'total_lessons' => 24, 'description' => 'Tiếng Anh giao tiếp phản xạ tự nhiên chuẩn giọng Mỹ'],
            ['code' => 'GT-B2', 'name' => 'Giao tiếp Pro B2', 'course_level_id' => $levelB2?->id, 'tuition_fee' => 11500000, 'total_lessons' => 24, 'description' => 'Thuyết trình, đàm phán và tiếng Anh thương mại'],
            ['code' => 'STARTER', 'name' => 'Tiếng Anh Mất Gốc', 'course_level_id' => CourseLevel::where('code', 'A1')->first()?->id, 'tuition_fee' => 6900000, 'total_lessons' => 16, 'description' => 'Xây dựng lại nền tảng phát âm IPA và ngữ pháp căn bản'],
        ];
        foreach ($coursesData as $cd) {
            $cd['name'] = '# ' . $cd['name'];
            Course::query()->updateOrCreate(['code' => $cd['code']], $cd + ['is_active' => true]);
        }
        $courseIE65 = Course::where('code', 'IE-65')->first();
        $courseGTB1 = Course::where('code', 'GT-B1')->first();

        // 3. Lớp học
        $classesData = [
            [
                'code' => 'IE-2408',
                'name' => 'Lớp IELTS 6.5 Intensive K24',
                'course_id' => $courseIE65?->id,
                'branch_id' => $branchCG?->id,
                'teacher_id' => $teacher?->id,
                'assistant_id' => $academic?->id,
                'schedule_text' => 'Tối Thứ 2 - 4 - 6 (19:30 - 21:30)',
                'start_date' => '2026-08-22',
                'end_date' => '2026-11-20',
                'max_capacity' => 15,
                'status' => 'active',
            ],
            [
                'code' => 'GT-08',
                'name' => 'Lớp Giao tiếp Pro B1 - GT08',
                'course_id' => $courseGTB1?->id,
                'branch_id' => $branchBD?->id,
                'teacher_id' => $teacher?->id,
                'assistant_id' => $academic?->id,
                'schedule_text' => 'Tối Thứ 3 - 5 - 7 (19:30 - 21:30)',
                'start_date' => '2026-08-25',
                'end_date' => '2026-11-25',
                'max_capacity' => 12,
                'status' => 'active',
            ],
        ];
        foreach ($classesData as $cld) {
            $cld['name'] = '# ' . $cld['name'];
            ClassModel::query()->updateOrCreate(['code' => $cld['code']], $cld);
        }
        $classIE2408 = ClassModel::where('code', 'IE-2408')->first();
        $classGT08 = ClassModel::where('code', 'GT-08')->first();

        // 4. Học viên & Học phí
        $studentsData = [
            [
                'code' => 'HV-00101',
                'name' => 'Nguyễn Minh Anh',
                'phone' => '0988 123 456',
                'email' => 'minhanh.ng@gmail.com',
                'dob' => '2004-06-15',
                'gender' => 'Nữ',
                'address' => 'Số 15 Cầu Giấy, Hà Nội',
                'branch_id' => $branchCG?->id,
                'current_class_id' => $classIE2408?->id,
                'target' => 'IELTS 6.5 Overall',
                'entrance_score' => '5.0 Overall',
                'midterm_score' => '6.0 Overall',
                'attended_lessons' => 22,
                'total_lessons' => 24,
                'homework_rate' => 96.0,
                'status' => 'studying',
                'tuition_fee' => 12500000,
                'paid_fee' => 12500000,
                'due_date' => '2026-08-20',
            ],
            [
                'code' => 'HV-00102',
                'name' => 'Vũ Thị Minh Hằng',
                'phone' => '0983 234 567',
                'email' => 'minhhang.vu@gmail.com',
                'dob' => '2003-09-20',
                'gender' => 'Nữ',
                'address' => '28 Trần Thái Tông, Cầu Giấy, Hà Nội',
                'branch_id' => $branchCG?->id,
                'current_class_id' => $classIE2408?->id,
                'target' => 'IELTS 6.5 Overall',
                'entrance_score' => '5.5 Overall',
                'midterm_score' => '6.5 Overall',
                'attended_lessons' => 20,
                'total_lessons' => 24,
                'homework_rate' => 90.0,
                'status' => 'studying',
                'tuition_fee' => 12500000,
                'paid_fee' => 12500000,
                'due_date' => '2026-08-20',
            ],
            [
                'code' => 'HV-00103',
                'name' => 'Nguyễn Đình Trọng',
                'phone' => '0912 345 678',
                'email' => 'dinhtrong.ng@gmail.com',
                'dob' => '2002-12-10',
                'gender' => 'Nam',
                'address' => '102 Nguyễn Chí Thanh, Hà Nội',
                'branch_id' => $branchBD?->id,
                'current_class_id' => $classGT08?->id,
                'target' => 'Giao tiếp B1',
                'entrance_score' => 'A2 Elementary',
                'midterm_score' => 'B1',
                'attended_lessons' => 18,
                'total_lessons' => 24,
                'homework_rate' => 85.0,
                'status' => 'studying',
                'tuition_fee' => 9500000,
                'paid_fee' => 4500000,
                'due_date' => '2026-08-28',
            ],
            [
                'code' => 'HV-00104',
                'name' => 'Trần Hồng Sơn',
                'phone' => '0945 999 888',
                'email' => 'hongson.tran@gmail.com',
                'dob' => '2001-03-25',
                'gender' => 'Nam',
                'address' => '45 Kim Mã, Ba Đình, Hà Nội',
                'branch_id' => $branchBD?->id,
                'current_class_id' => $classGT08?->id,
                'target' => 'Giao tiếp B2',
                'entrance_score' => 'B1',
                'midterm_score' => 'B2',
                'attended_lessons' => 15,
                'total_lessons' => 24,
                'homework_rate' => 75.0,
                'status' => 'studying',
                'tuition_fee' => 9500000,
                'paid_fee' => 2600000,
                'due_date' => '2026-08-12', // Quá hạn
            ],
        ];

        foreach ($studentsData as $sd) {
            $sd['name'] = '# ' . $sd['name'];
            $tuitionFee = $sd['tuition_fee'];
            $paidFee = $sd['paid_fee'];
            $dueDate = $sd['due_date'];
            unset($sd['tuition_fee'], $sd['paid_fee'], $sd['due_date']);

            $student = Student::query()->updateOrCreate(['code' => $sd['code']], $sd);

            // Enrollment
            if ($student->current_class_id) {
                ClassEnrollment::query()->firstOrCreate(
                    ['student_id' => $student->id, 'class_id' => $student->current_class_id],
                    ['enrolled_at' => now(), 'curriculum_delivered' => true, 'zalo_group_added' => true, 'status' => 'completed']
                );
            }

            // Student Tuition
            $debt = max(0, $tuitionFee - $paidFee);
            $status = ($debt == 0) ? 'paid' : ($paidFee > 0 ? 'partial' : 'unpaid');
            if ($dueDate < date('Y-m-d') && $debt > 0) {
                $status = 'overdue';
            }

            $tuition = StudentTuition::query()->updateOrCreate(
                ['student_id' => $student->id],
                [
                    'class_id' => $student->current_class_id,
                    'branch_id' => $student->branch_id,
                    'total_amount' => $tuitionFee,
                    'discount_amount' => 0,
                    'final_amount' => $tuitionFee,
                    'paid_amount' => $paidFee,
                    'debt_amount' => $debt,
                    'due_date' => $dueDate,
                    'status' => $status,
                ]
            );

            // Receipt
            if ($paidFee > 0) {
                TuitionReceipt::query()->firstOrCreate(
                    ['student_tuition_id' => $tuition->id],
                    [
                        'receipt_number' => 'PT-2026-' . str_pad($student->id, 4, '0', STR_PAD_LEFT),
                        'amount' => $paidFee,
                        'payment_method' => 'transfer',
                        'transaction_code' => 'FT260814' . str_pad($student->id, 4, '0', STR_PAD_LEFT),
                        'payment_date' => now()->subDays(3),
                        'creator_id' => $admin?->id,
                        'approver_id' => $accountant?->id,
                        'status' => 'approved',
                        'notes' => 'Thu học phí chuyển khoản VietQR',
                    ]
                );
            }
        }

        // 4.1. 4 Phiếu thu chờ duyệt theo mockup giao diện chuẩn (Screen #14)
        $c1 = ClassModel::firstOrCreate(['name' => '# IELTS Foundation 02'], ['code' => 'IF-02', 'branch_id' => $branchCG->id, 'status' => 'active']);
        $c2 = ClassModel::firstOrCreate(['name' => '# Tiếng Anh Giao Tiếp'], ['code' => 'GT-CB', 'branch_id' => $branchDD->id, 'status' => 'active']);
        $c3 = ClassModel::firstOrCreate(['name' => '# Speaking Master'], ['code' => 'SPK-M', 'branch_id' => $branchCG->id, 'status' => 'active']);
        $c4 = ClassModel::firstOrCreate(['name' => '# Combo IELTS Intensive'], ['code' => 'IE-INT', 'branch_id' => $branchHBT->id, 'status' => 'active']);

        $pendingPrototypeReceipts = [
            [
                'receipt_number' => 'PT-20231024-001',
                'student_name' => 'Nguyễn Văn A',
                'student_code' => 'MS-24098',
                'parent_name' => 'Nguyễn Văn Tuấn',
                'phone' => '0987654321',
                'branch_id' => $branchCG->id,
                'class_id' => $c1->id,
                'amount' => 13500000,
                'tuition_amount' => 12000000,
                'surcharge_amount' => 1500000,
                'surcharge_reason' => 'Bộ giáo trình Cambridge IELTS trọn bộ & Tài liệu số LMS',
                'discount_amount' => 1500000,
                'payment_method' => 'transfer',
                'transaction_code' => 'FT232981354789',
                'notes' => 'Phụ huynh đã chuyển khoản đúng số tiền 13.5tr qua QR thanh toán Vietcombank vào 10:42 sáng nay.',
                'minutes_ago' => 15,
            ],
            [
                'receipt_number' => 'PT-20231024-002',
                'student_name' => 'Trần Thị Mai',
                'student_code' => 'MS-23841',
                'parent_name' => 'Trần Thị Mai',
                'phone' => '0912345678',
                'branch_id' => $branchDD->id,
                'class_id' => $c2->id,
                'amount' => 8200000,
                'tuition_amount' => 8200000,
                'surcharge_amount' => 0,
                'surcharge_reason' => null,
                'discount_amount' => 0,
                'payment_method' => 'cash',
                'paper_invoice_number' => 'Biên lai thu số #889',
                'notes' => 'Nộp tiền mặt tại quầy lễ tân cơ sở Đống Đa, đã xuất phiếu thu giấy số #889.',
                'minutes_ago' => 35,
            ],
            [
                'receipt_number' => 'PT-20231024-003',
                'student_name' => 'Vũ Thị Thu Thảo',
                'student_code' => 'MS-23981',
                'parent_name' => 'Vũ Thị Thu Thảo',
                'phone' => '0983112233',
                'branch_id' => $branchCG->id,
                'class_id' => $c3->id,
                'amount' => 14800000,
                'tuition_amount' => 14800000,
                'surcharge_amount' => 0,
                'surcharge_reason' => null,
                'discount_amount' => 0,
                'payment_method' => 'transfer',
                'transaction_code' => 'FT23981023ACB',
                'notes' => 'Thanh toán chuyển khoản trực tuyến ngân hàng ACB - Speaking Master trọn gói.',
                'minutes_ago' => 60,
            ],
            [
                'receipt_number' => 'PT-20231024-004',
                'student_name' => 'Phạm Đức Long',
                'student_code' => 'MS-24219',
                'parent_name' => 'Phạm Văn Dũng',
                'phone' => '0977889900',
                'branch_id' => $branchHBT->id,
                'class_id' => $c4->id,
                'amount' => 6150000,
                'tuition_amount' => 4500000,
                'surcharge_amount' => 1650000,
                'surcharge_reason' => 'Phụ thu giáo trình & lệ phí thi thử',
                'discount_amount' => 0,
                'payment_method' => 'transfer',
                'transaction_code' => 'FT24219084TCB',
                'notes' => 'Chuyển khoản UNC Techcombank - Phụ thu giáo trình và đăng ký thi thử Mock test.',
                'minutes_ago' => 120,
            ],
        ];

        foreach ($pendingPrototypeReceipts as $p) {
            $protoStudent = Student::query()->updateOrCreate(
                ['code' => $p['student_code']],
                [
                    'name' => '# ' . $p['student_name'],
                    'phone' => $p['phone'],
                    'branch_id' => $p['branch_id'],
                    'current_class_id' => $p['class_id'],
                    'status' => 'studying',
                ]
            );

            $protoTuition = StudentTuition::query()->updateOrCreate(
                ['student_id' => $protoStudent->id, 'class_id' => $p['class_id']],
                [
                    'branch_id' => $p['branch_id'],
                    'total_amount' => $p['amount'],
                    'discount_amount' => $p['discount_amount'],
                    'final_amount' => $p['amount'],
                    'paid_amount' => 0,
                    'debt_amount' => $p['amount'],
                    'due_date' => now()->addDays(7),
                    'status' => 'pending',
                ]
            );

            TuitionReceipt::query()->updateOrCreate(
                ['receipt_number' => $p['receipt_number']],
                [
                    'student_id' => $protoStudent->id,
                    'student_tuition_id' => $protoTuition->id,
                    'amount' => $p['amount'],
                    'tuition_amount' => $p['tuition_amount'],
                    'surcharge_amount' => $p['surcharge_amount'],
                    'surcharge_reason' => $p['surcharge_reason'],
                    'discount_amount' => $p['discount_amount'],
                    'payment_method' => $p['payment_method'],
                    'transaction_code' => $p['transaction_code'] ?? null,
                    'paper_invoice_number' => $p['paper_invoice_number'] ?? null,
                    'payer_name' => $p['parent_name'],
                    'payer_phone' => $p['phone'],
                    'status' => 'pending',
                    'creator_id' => $admin?->id,
                    'approver_id' => null,
                    'notes' => $p['notes'],
                    'created_at' => now()->subMinutes($p['minutes_ago']),
                    'updated_at' => now()->subMinutes($p['minutes_ago']),
                ]
            );
        }

        // 5. Cấu hình Hóa đơn
        InvoiceConfiguration::query()->firstOrCreate(
            ['template_code' => '1/001'],
            ['series_code' => 'C26MEN', 'start_number' => 1, 'current_number' => 890, 'provider' => 'VNPT Invoice', 'auto_issue' => true]
        );

        // 6. Đơn giá GV & Mốc hoa hồng
        TeacherRate::query()->updateOrCreate(
            ['rank_title' => 'Junior Teacher'],
            ['criteria' => 'IELTS 7.0+, < 1 năm KN', 'communication_rate' => 180000, 'ielts_rate' => 220000]
        );
        TeacherRate::query()->updateOrCreate(
            ['rank_title' => 'Senior Teacher'],
            ['criteria' => 'IELTS 8.0+, TESOL, > 2 năm KN', 'communication_rate' => 250000, 'ielts_rate' => 300000]
        );
        TeacherRate::query()->updateOrCreate(
            ['rank_title' => 'Master / Native Speaker'],
            ['criteria' => 'Thạc sĩ TESOL / Giáo viên Bản ngữ', 'communication_rate' => 450000, 'ielts_rate' => 600000]
        );

        CommissionTier::query()->updateOrCreate(['tier_name' => 'Mức 1 (Cơ bản)'], ['min_revenue' => 50000000, 'max_revenue' => 100000000, 'new_sale_percent' => 3.0, 'renew_percent' => 5.0, 'bonus_amount' => 1000000]);
        CommissionTier::query()->updateOrCreate(['tier_name' => 'Mức 2 (Nâng cao)'], ['min_revenue' => 100000001, 'max_revenue' => 200000000, 'new_sale_percent' => 4.5, 'renew_percent' => 7.0, 'bonus_amount' => 2500000]);
        CommissionTier::query()->updateOrCreate(['tier_name' => 'Mức 3 (Xuất sắc)'], ['min_revenue' => 200000001, 'max_revenue' => null, 'new_sale_percent' => 6.0, 'renew_percent' => 10.0, 'bonus_amount' => 5000000]);

        // 7. Bảng lương kỳ & Timesheets
        $period = PayrollPeriod::query()->updateOrCreate(
            ['code' => 'PR-2026-08'],
            [
                'title' => '# Bảng lương Tháng 08/2026',
                'month' => 8,
                'year' => 2026,
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-31',
                'status' => 'draft',
                'total_staff' => 38,
                'total_hours' => 486.5,
                'total_amount' => 342600000,
            ]
        );

        PayrollRecord::query()->updateOrCreate(
            ['payroll_period_id' => $period->id, 'user_id' => $teacher?->id],
            [
                'department' => 'fulltime',
                'base_salary' => 15000000,
                'standard_hours' => 40,
                'actual_hours' => 64,
                'overtime_hours' => 24,
                'teaching_salary' => 7200000,
                'kpi_bonus' => 3500000,
                'renew_bonus' => 1500000,
                'allowance' => 1000000,
                'insurance_deduction' => 1575000,
                'tax_deduction' => 850000,
                'penalty_deduction' => 0,
                'net_salary' => 25775000,
                'status' => 'pending',
                'notes' => 'Senior IELTS Teacher - Dạy vượt 24h định mức',
            ]
        );

        TeacherTimesheet::query()->firstOrCreate(
            ['user_id' => $teacher?->id, 'teaching_date' => now()->format('Y-m-d')],
            [
                'class_id' => $classIE2408?->id,
                'scheduled_time' => '19:30 - 21:30',
                'checkin_time' => '19:20',
                'hours' => 2.0,
                'hourly_rate' => 300000,
                'type' => 'regular',
                'status' => 'valid',
                'notes' => 'Giảng dạy buổi 4: IELTS Writing Task 2',
            ]
        );

        // Không seed TimesheetSyncLog: chưa có tích hợp máy chấm công nào ghi log,
        // dòng giả sẽ bị hiểu nhầm là lần đồng bộ thật trên màn Lịch sử đồng bộ.

        // 8. Kỷ luật & Phạt
        Penalty::query()->updateOrCreate(
            ['code' => 'BB-2026-014'],
            [
                'user_id' => $teacher?->id,
                'class_id' => $classIE2408?->id,
                'violation_type' => 'Đến muộn > 15 phút (Buổi 02)',
                'violation_date' => '2026-08-10',
                'amount' => 200000,
                'reporter_id' => $admin?->id,
                'status' => 'confirmed',
                'notes' => 'Đã xác nhận biên bản và trừ vào kỳ lương tháng 8',
            ]
        );

        // 9. Đề Test Đầu Vào
        $test01 = PlacementTest::query()->updateOrCreate(
            ['code' => 'TEST-01'],
            [
                'title' => '# Đề Test 4 Kỹ Năng - Standard 2026',
                'target_level' => 'Tổng hợp A1 - B2',
                'duration_minutes' => 45,
                'questions_count' => 5,
                'is_active' => true,
                'is_preset' => true,
                'questions' => [
                    [
                        'id' => 1,
                        'skill' => 'listening',
                        'type' => 'multiple_choice',
                        'title' => "What is the passenger's final destination in the conversation?",
                        'audio_url' => '/uploads/2026/dethitest/de-test-lop-6-len-7/track-1-4-20260819105025-7k9aa.mp3',
                        'passage' => 'Listen to the audio clip at Customer Service Desk.',
                        'options' => [
                            ['key' => 'A', 'text' => 'London Heathrow'],
                            ['key' => 'B', 'text' => 'Melbourne International Airport'],
                            ['key' => 'C', 'text' => 'Tokyo Narita'],
                            ['key' => 'D', 'text' => 'Singapore Changi'],
                        ],
                        'correct_answer' => 'B',
                        'points' => 1,
                        'explanation' => 'The passenger confirms connecting flight to Melbourne.',
                    ],
                    [
                        'id' => 2,
                        'skill' => 'reading',
                        'type' => 'multiple_choice',
                        'title' => 'According to the passage, what is the primary benefit of renewable energy?',
                        'passage' => 'Renewable energy sources, such as solar and wind power, emit little to no greenhouse gases during operation. In addition, they decrease reliance on finite fossil fuel reserves and stimulate local job growth in clean tech sectors.',
                        'options' => [
                            ['key' => 'A', 'text' => 'It eliminates the need for power grids'],
                            ['key' => 'B', 'text' => 'It significantly reduces greenhouse gas emissions'],
                            ['key' => 'C', 'text' => 'It requires no initial capital investment'],
                            ['key' => 'D', 'text' => 'It operates without any maintenance'],
                        ],
                        'correct_answer' => 'B',
                        'points' => 1,
                        'explanation' => 'The passage explicitly states that renewable energy emits little to no greenhouse gases.',
                    ],
                    [
                        'id' => 3,
                        'skill' => 'grammar',
                        'type' => 'fill_blank',
                        'title' => 'Complete the sentence: If she _____ (study) harder last month, she would have passed the IELTS exam.',
                        'correct_answer' => 'had studied',
                        'points' => 1,
                        'explanation' => 'Third conditional structure: If + S + had + V3/ed, S + would have + V3/ed.',
                    ],
                    [
                        'id' => 4,
                        'skill' => 'writing',
                        'type' => 'essay',
                        'title' => 'Writing Task: Some people believe that studying online is more effective than traditional classroom learning. Discuss both views and give your opinion.',
                        'min_words' => 120,
                        'rubric_note' => 'Chấm theo tiêu chí: Task Response, Coherence & Cohesion, Lexical Resource, Grammar Accuracy.',
                        'points' => 9,
                    ],
                    [
                        'id' => 5,
                        'skill' => 'speaking',
                        'type' => 'speaking_prompt',
                        'title' => 'Speaking Part 2: Describe a memorable journey or trip you took.',
                        'cue_points' => "• Where you went and who you went with\n• How you travelled there\n• What you did during the trip\n• And explain why this trip was so memorable for you",
                        'points' => 9,
                    ],
                ],
            ]
        );

        PlacementTest::query()->updateOrCreate(
            ['code' => 'TEST-IE-2026'],
            [
                'title' => '# Đề Test Đầu Vào IELTS Intensive 2026',
                'target_level' => 'IELTS Intensive (5.0 - 6.5)',
                'duration_minutes' => 60,
                'questions_count' => 5,
                'is_active' => true,
                'is_preset' => true,
                'questions' => $test01->questions,
            ]
        );

        PlacementTestSubmission::query()->firstOrCreate(
            ['placement_test_id' => $test01->id, 'candidate_phone' => '0982 345 678'],
            [
                'candidate_name' => 'Nguyễn Thị Thuỳ Dung',
                'candidate_email' => 'thuydung.ng@gmail.com',
                'listening_score' => 5.5,
                'reading_score' => 5.0,
                'writing_score' => 4.5,
                'speaking_score' => 5.0,
                'overall_score' => 5.0,
                'cefr_level' => 'B1',
                'writing_content' => 'In my opinion, learning English is very important for young people today because it helps us find good jobs...',
                'speaking_audio_url' => 'https://api.menglish.edu.vn/audio/samples/sample_speaking_dung.mp3',
                'recommended_course' => 'IELTS 6.5 Intensive',
                'teacher_comments' => 'Học viên tiếp thu tốt, từ vựng nền tảng khá, đủ điều kiện vào học khóa IELTS 6.5 Intensive.',
                'grader_id' => $teacher?->id,
                'status' => 'graded',
            ]
        );

        // 10. Syllabus & Big Test
        $curriculum = SyllabusCurriculum::query()->updateOrCreate(
            ['code' => 'CUR-IE65'],
            [
                'title' => '# MEnglish IELTS Intensive 6.5 - Student Book',
                'course_id' => $courseIE65?->id,
                'version' => 'v3.2',
                'file_type' => 'PDF',
                'file_size' => '42 MB',
                'file_url' => 'https://menglish.edu.vn/files/ielts_65_curriculum.pdf',
                'description' => 'Giáo trình chuẩn xuất bản quý 3/2026',
            ]
        );

        SyllabusUnit::query()->updateOrCreate(
            ['curriculum_id' => $curriculum->id, 'unit_number' => 4],
            [
                'stage_id' => $curriculum->stages()->value('id'),
                'title' => '# Unit 04: Environment & Climate Change',
                'objectives' => 'Nắm 20 từ vựng chủ đề môi trường, câu điều kiện hỗn hợp và phản xạ Speaking Part 3',
                'vocabulary_focus' => 'Deforestation, greenhouse effect, carbon footprint, renewable energy...',
                'grammar_focus' => 'Mixed Conditionals, Inversion with negative adverbs',
                'homework_guide' => 'Hoàn thành bài viết Writing Task 2 (250 từ) trên LMS',
            ]
        );

        SyllabusAssignment::query()->updateOrCreate(
            ['curriculum_id' => $curriculum->id, 'user_id' => $teacher?->id],
            [
                'assigned_chapters' => 'Unit 5 - 8 (Writing Task 2 Academic)',
                'deadline' => '2026-08-25',
                'progress_percent' => 75,
                'status' => 'in_progress',
            ]
        );

        $bigTest = BigTest::query()->updateOrCreate(
            ['code' => 'BT-2026-08'],
            [
                'title' => '# Mid-Term Big Test #08 (Giữa Kỳ)',
                'class_id' => $classIE2408?->id,
                'test_type' => 'midterm',
                'scheduled_at' => '2026-08-25 19:30:00',
                'room' => 'Phòng Lab 201 · Cơ sở Cầu Giấy',
                'proctor_id' => $academic?->id,
                'passcode' => 'MEN2026BT',
                'is_distributed' => true,
            ]
        );

        $student1 = Student::where('code', 'HV-00101')->first();
        if ($student1) {
            BigTestResult::query()->updateOrCreate(
                ['big_test_id' => $bigTest->id, 'student_id' => $student1->id],
                [
                    'listening_score' => 6.5,
                    'reading_score' => 6.0,
                    'writing_score' => 5.5,
                    'speaking_score' => 6.0,
                    'overall_score' => 6.0,
                    'progress_note' => 'Tăng +1.0 Band so với đầu vào',
                    'parent_notified' => false,
                    'notified_at' => null,
                ]
            );
        }

        $student2 = Student::where('code', 'HV-00102')->first();
        if ($student2) {
            BigTestResult::query()->updateOrCreate(
                ['big_test_id' => $bigTest->id, 'student_id' => $student2->id],
                [
                    'listening_score' => 7.0,
                    'reading_score' => 6.5,
                    'writing_score' => 6.0,
                    'speaking_score' => 6.5,
                    'overall_score' => 6.5,
                    'progress_note' => 'Đạt mục tiêu đầu ra IELTS 6.5',
                    'parent_notified' => false,
                    'notified_at' => null,
                ]
            );
        }

        $student3 = Student::where('code', 'HV-00103')->first();
        if ($student3) {
            BigTestResult::query()->updateOrCreate(
                ['big_test_id' => $bigTest->id, 'student_id' => $student3->id],
                [
                    'listening_score' => 5.5,
                    'reading_score' => 5.5,
                    'writing_score' => 5.0,
                    'speaking_score' => 5.5,
                    'overall_score' => 5.4,
                    'progress_note' => 'Cần bổ trợ thêm kỹ năng Viết Task 2',
                    'parent_notified' => false,
                    'notified_at' => null,
                ]
            );
        }

        // 11. Tài khoản ngân hàng & Nhắc nợ
        BankAccount::query()->updateOrCreate(
            ['account_number' => '9988 2345 6789'],
            [
                'bank_code' => 'VCB',
                'bank_name' => 'Vietcombank (Ngoại thương Việt Nam)',
                'account_holder' => 'CONG TY CO PHAN GIAO DUC MENGLISH',
                'branch_location' => 'Chi nhánh Cầu Giấy, Hà Nội',
                'branch_id' => $branchCG?->id,
                'is_default_vietqr' => true,
                'is_active' => true,
            ]
        );

        DebtReminderRule::query()->updateOrCreate(
            ['milestone_key' => 'T-3'],
            [
                'title' => 'Mốc 1: Nhắc trước hạn 3 ngày (T-3)',
                'template_content' => 'Chào {TEN_HOC_VIEN}, học phí lớp {TEN_LOP} tại MEnglish sẽ đến hạn vào ngày {HAN_NOP} với số tiền {SO_TIEN}. Quý phụ huynh vui lòng thanh toán theo QR kèm theo.',
                'is_enabled' => true,
            ]
        );
        DebtReminderRule::query()->updateOrCreate(
            ['milestone_key' => 'T0'],
            [
                'title' => 'Mốc 2: Nhắc đúng ngày đến hạn (T0)',
                'template_content' => 'MEnglish xin thông báo: Hôm nay là hạn đóng học phí đợt tiếp theo của học viên {TEN_HOC_VIEN}. Số tiền: {SO_TIEN}. Cảm ơn Quý phụ huynh!',
                'is_enabled' => true,
            ]
        );
        DebtReminderRule::query()->updateOrCreate(
            ['milestone_key' => 'T+3'],
            [
                'title' => 'Mốc 3: Cảnh báo quá hạn 3 ngày (T+3)',
                'template_content' => 'Kính gửi Quý phụ huynh, học phí của học viên {TEN_HOC_VIEN} đã quá hạn 3 ngày. Vui lòng hoàn tất thanh toán để đảm bảo quyền lợi học tập của học viên.',
                'is_enabled' => true,
            ]
        );
    }
}
