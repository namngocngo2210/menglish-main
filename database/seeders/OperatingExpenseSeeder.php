<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\OperatingExpense;
use App\Models\Branch;
use App\Models\User;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Student;
use App\Models\StudentTuition;
use App\Models\TuitionReceipt;
use Carbon\Carbon;

class OperatingExpenseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::first();
        $adminId = $admin?->id ?? 1;

        $cauGiay = Branch::where('name', 'like', '%Cầu Giấy%')->first();
        $dongDa = Branch::where('name', 'like', '%Đống Đa%')->first();
        $haiBaTrung = Branch::where('name', 'like', '%Hai Bà Trưng%')->first();

        // 1. Seed Khoản chi vận hành Tháng 09/2026
        $expensesSept = [
            [
                'expense_date' => '2026-09-18',
                'title' => 'Tiền thuê mặt bằng cơ sở Cầu Giấy (Tháng 09)',
                'amount' => 15000000,
                'payment_method' => 'chuyen_khoan',
                'branch_id' => $cauGiay?->id,
                'category' => 'mat_bang_tien_ich',
                'notes' => 'Thanh toán chuyển khoản định kỳ chủ nhà tầng 2-3 theo HĐ thuê 2024',
                'creator_id' => $adminId,
            ],
            [
                'expense_date' => '2026-09-15',
                'title' => 'In ấn giáo trình IELTS Foundation & Sách bổ trợ',
                'amount' => 4850000,
                'payment_method' => 'chuyen_khoan',
                'branch_id' => $cauGiay?->id,
                'category' => 'giao_trinh_van_hanh',
                'notes' => 'Xưởng in Minh Châu (Hóa đơn VAT số 004128)',
                'creator_id' => $adminId,
            ],
            [
                'expense_date' => '2026-09-12',
                'title' => 'Mua văn phòng phẩm (Giấy A4, bút lông, mực viết bảng)',
                'amount' => 1250000,
                'payment_method' => 'tien_mat',
                'branch_id' => $dongDa?->id,
                'category' => 'giao_trinh_van_hanh',
                'notes' => 'Mua lẻ nhà sách Tiền Phong, có hóa đơn bán lẻ kèm theo',
                'creator_id' => $adminId,
            ],
            [
                'expense_date' => '2026-09-08',
                'title' => 'Sửa chữa bảo dưỡng hệ thống điều hòa P.201 - P.203',
                'amount' => 2400000,
                'payment_method' => 'tien_mat',
                'branch_id' => $cauGiay?->id,
                'category' => 'giao_trinh_van_hanh',
                'notes' => 'Nạp gas và vệ sinh lưới lọc 4 máy điều hòa phòng học lớn',
                'creator_id' => $adminId,
            ],
            [
                'expense_date' => '2026-09-05',
                'title' => 'Tiền điện nước và Internet cáp quang tháng 08',
                'amount' => 1850000,
                'payment_method' => 'chuyen_khoan',
                'branch_id' => $haiBaTrung?->id,
                'category' => 'mat_bang_tien_ich',
                'notes' => 'Ủy nhiệm chi tự động VNPT & EVN Hà Nội',
                'creator_id' => $adminId,
            ],
            [
                'expense_date' => '2026-09-02',
                'title' => 'Nước uống đóng bình Lavie & Trà tiếp đón phụ huynh',
                'amount' => 1000000,
                'payment_method' => 'tien_mat',
                'branch_id' => $cauGiay?->id,
                'category' => 'giao_trinh_van_hanh',
                'notes' => '15 bình Lavie 19L giao tận nơi kèm gói bánh tiếp khách',
                'creator_id' => $adminId,
            ],
        ];

        foreach ($expensesSept as $exp) {
            OperatingExpense::firstOrCreate(
                ['title' => $exp['title'], 'expense_date' => $exp['expense_date']],
                $exp
            );
        }

        // 2. Seed Khoản chi Tháng 08/2026 (để so sánh)
        $expensesAug = [
            [
                'expense_date' => '2026-08-18',
                'title' => 'Tiền thuê mặt bằng cơ sở Cầu Giấy (Tháng 08)',
                'amount' => 15000000,
                'payment_method' => 'chuyen_khoan',
                'branch_id' => $cauGiay?->id,
                'category' => 'mat_bang_tien_ich',
                'notes' => 'HĐ thuê trụ sở',
                'creator_id' => $adminId,
            ],
            [
                'expense_date' => '2026-08-15',
                'title' => 'In ấn tài liệu Speaking Master đợt 2',
                'amount' => 5200000,
                'payment_method' => 'chuyen_khoan',
                'branch_id' => $cauGiay?->id,
                'category' => 'giao_trinh_van_hanh',
                'notes' => 'Xưởng in ấn',
                'creator_id' => $adminId,
            ],
            [
                'expense_date' => '2026-08-10',
                'title' => 'Văn phòng phẩm tháng 8',
                'amount' => 1500000,
                'payment_method' => 'tien_mat',
                'branch_id' => $dongDa?->id,
                'category' => 'giao_trinh_van_hanh',
                'notes' => 'VPP',
                'creator_id' => $adminId,
            ],
            [
                'expense_date' => '2026-08-05',
                'title' => 'Tiền điện nước Internet tháng 7',
                'amount' => 1900000,
                'payment_method' => 'chuyen_khoan',
                'branch_id' => $haiBaTrung?->id,
                'category' => 'mat_bang_tien_ich',
                'notes' => 'Hóa đơn tháng 7',
                'creator_id' => $adminId,
            ],
        ];

        foreach ($expensesAug as $exp) {
            OperatingExpense::firstOrCreate(
                ['title' => $exp['title'], 'expense_date' => $exp['expense_date']],
                $exp
            );
        }

        // 3. Seed hoặc Update Bảng lương Tháng 09/2026 (Epic 7)
        $periodSept = PayrollPeriod::firstOrCreate(
            ['month' => 9, 'year' => 2026],
            [
                'code' => 'BL-2026-09',
                'title' => 'Bảng lương Tháng 09/2026',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-30',
                'status' => 'paid',
                'total_staff' => 18,
                'total_hours' => 320,
                'total_amount' => 98500000,
            ]
        );

        if ($periodSept->total_amount != 98500000 || $periodSept->status != 'paid') {
            $periodSept->update([
                'status' => 'paid',
                'total_staff' => 18,
                'total_amount' => 98500000,
            ]);
        }

        // Cập nhật hoặc tạo PayrollRecord cho các nhân viên để lọc theo branch
        $allUsers = User::all();
        if ($allUsers->count() > 0) {
            $branchDistribution = [
                $cauGiay?->id => 50000000, // Cầu Giấy ~50M
                $dongDa?->id => 30000000,  // Đống Đa ~30M
                $haiBaTrung?->id => 18500000, // Hai Bà Trưng ~18.5M
            ];

            foreach ($allUsers as $index => $u) {
                $targetBranchId = match ($index % 3) {
                    0 => $cauGiay?->id,
                    1 => $dongDa?->id,
                    default => $haiBaTrung?->id,
                };
                if (!$u->branch_id && $targetBranchId) {
                    $u->update(['branch_id' => $targetBranchId]);
                }

                $recordAmount = ($index < 18) ? round(98500000 / 18, -4) : 0;
                if ($index == 17) {
                    // Cân đối tròn 98.5M
                    $currentSum = PayrollRecord::where('payroll_period_id', $periodSept->id)->where('id', '!=', $u->id)->sum('net_salary');
                    $recordAmount = 98500000 - $currentSum;
                    if ($recordAmount <= 0) $recordAmount = 5000000;
                }

                PayrollRecord::firstOrCreate(
                    ['payroll_period_id' => $periodSept->id, 'user_id' => $u->id],
                    [
                        'department' => 'Academic',
                        'base_salary' => $recordAmount * 0.7,
                        'standard_hours' => 20,
                        'actual_hours' => 20,
                        'teaching_salary' => $recordAmount * 0.3,
                        'net_salary' => $recordAmount,
                        'status' => 'paid',
                    ]
                );
            }
        }

        // 4. Seed Phiếu thu Học phí (TuitionReceipts) Tháng 09/2026
        // Tổng thu: 418.500.000 VNĐ (402.600.000 học phí + 15.900.000 phụ thu)
        $receiptsData = [
            // Cầu Giấy (CS1): ~225.400.000 đ
            ['receipt_number' => 'PT-2026-0901', 'amount' => 85000000, 'tuition_amount' => 82000000, 'surcharge_amount' => 3000000, 'surcharge_reason' => 'Giáo trình IELTS', 'branch_id' => $cauGiay?->id, 'payment_method' => 'transfer', 'date' => '2026-09-03'],
            ['receipt_number' => 'PT-2026-0902', 'amount' => 78400000, 'tuition_amount' => 75000000, 'surcharge_amount' => 3400000, 'surcharge_reason' => 'Lệ phí thi thử', 'branch_id' => $cauGiay?->id, 'payment_method' => 'transfer', 'date' => '2026-09-08'],
            ['receipt_number' => 'PT-2026-0903', 'amount' => 62000000, 'tuition_amount' => 60000000, 'surcharge_amount' => 2000000, 'surcharge_reason' => 'Thẻ học viên & Áo đồng phục', 'branch_id' => $cauGiay?->id, 'payment_method' => 'cash', 'date' => '2026-09-14'],

            // Đống Đa (CS2): ~128.600.000 đ
            ['receipt_number' => 'PT-2026-0904', 'amount' => 65000000, 'tuition_amount' => 62500000, 'surcharge_amount' => 2500000, 'surcharge_reason' => 'Sách bổ trợ', 'branch_id' => $dongDa?->id, 'payment_method' => 'transfer', 'date' => '2026-09-05'],
            ['receipt_number' => 'PT-2026-0905', 'amount' => 63600000, 'tuition_amount' => 61000000, 'surcharge_amount' => 2600000, 'surcharge_reason' => 'Giáo trình & Tài liệu', 'branch_id' => $dongDa?->id, 'payment_method' => 'transfer', 'date' => '2026-09-11'],

            // Hai Bà Trưng (CS3): ~64.500.000 đ
            ['receipt_number' => 'PT-2026-0906', 'amount' => 38500000, 'tuition_amount' => 37000000, 'surcharge_amount' => 1500000, 'surcharge_reason' => 'Lệ phí khảo sát', 'branch_id' => $haiBaTrung?->id, 'payment_method' => 'transfer', 'date' => '2026-09-07'],
            ['receipt_number' => 'PT-2026-0907', 'amount' => 26000000, 'tuition_amount' => 25100000, 'surcharge_amount' => 900000, 'surcharge_reason' => 'Giáo trình in ấn', 'branch_id' => $haiBaTrung?->id, 'payment_method' => 'cash', 'date' => '2026-09-16'],
        ];

        // Ensure at least 1 student exists per branch for foreign key
        foreach ($receiptsData as $r) {
            $student = Student::firstOrCreate(
                ['name' => 'Học viên ' . $r['receipt_number']],
                [
                    'code' => 'HV-' . substr($r['receipt_number'], 3),
                    'branch_id' => $r['branch_id'] ?? 1,
                    'phone' => '098' . rand(1000000, 9999999),
                    'status' => 'active',
                    'current_class_id' => 1,
                ]
            );

            $tuition = StudentTuition::firstOrCreate(
                ['student_id' => $student->id],
                [
                    'branch_id' => $r['branch_id'] ?? 1,
                    'class_id' => 1,
                    'total_amount' => $r['amount'],
                    'paid_amount' => $r['amount'],
                    'final_amount' => $r['amount'],
                    'debt_amount' => 0,
                    'status' => 'completed',
                    'due_date' => $r['date'],
                ]
            );

            TuitionReceipt::firstOrCreate(
                ['receipt_number' => $r['receipt_number']],
                [
                    'student_tuition_id' => $tuition->id,
                    'student_id' => $student->id,
                    'amount' => $r['amount'],
                    'tuition_amount' => $r['tuition_amount'],
                    'surcharge_amount' => $r['surcharge_amount'],
                    'surcharge_reason' => $r['surcharge_reason'],
                    'payment_method' => $r['payment_method'],
                    'payment_date' => $r['date'],
                    'status' => 'approved',
                    'creator_id' => $adminId,
                    'approver_id' => $adminId,
                    'notes' => 'Thu học phí kỳ 09/2026',
                ]
            );
        }

        // 5. Thêm một số phiếu thu Tháng 08/2026 để so sánh kỳ trước
        $augReceipts = [
            ['receipt_number' => 'PT-2026-0801', 'amount' => 190000000, 'branch_id' => $cauGiay?->id, 'date' => '2026-08-10'],
            ['receipt_number' => 'PT-2026-0802', 'amount' => 115000000, 'branch_id' => $dongDa?->id, 'date' => '2026-08-15'],
            ['receipt_number' => 'PT-2026-0803', 'amount' => 67300000, 'branch_id' => $haiBaTrung?->id, 'date' => '2026-08-20'],
        ];

        foreach ($augReceipts as $r) {
            $student = Student::firstOrCreate(
                ['name' => 'Học viên ' . $r['receipt_number']],
                [
                    'code' => 'HV-' . substr($r['receipt_number'], 3),
                    'branch_id' => $r['branch_id'] ?? 1,
                    'phone' => '097' . rand(1000000, 9999999),
                    'status' => 'active',
                    'current_class_id' => 1,
                ]
            );

            $tuition = StudentTuition::firstOrCreate(
                ['student_id' => $student->id],
                [
                    'branch_id' => $r['branch_id'] ?? 1,
                    'class_id' => 1,
                    'total_amount' => $r['amount'],
                    'paid_amount' => $r['amount'],
                    'final_amount' => $r['amount'],
                    'debt_amount' => 0,
                    'status' => 'completed',
                    'due_date' => $r['date'],
                ]
            );

            TuitionReceipt::firstOrCreate(
                ['receipt_number' => $r['receipt_number']],
                [
                    'student_tuition_id' => $tuition->id,
                    'student_id' => $student->id,
                    'amount' => $r['amount'],
                    'tuition_amount' => $r['amount'],
                    'surcharge_amount' => 0,
                    'payment_method' => 'transfer',
                    'payment_date' => $r['date'],
                    'status' => 'approved',
                    'creator_id' => $adminId,
                    'approver_id' => $adminId,
                    'notes' => 'Thu học phí kỳ 08/2026',
                ]
            );
        }
    }
}
