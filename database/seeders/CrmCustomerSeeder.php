<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class CrmCustomerSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();
        $branchCG = $branches->firstWhere('code', 'CG') ?? $branches->first();
        $branchBD = $branches->firstWhere('code', 'BD') ?? $branches->first();
        $branchDD = $branches->firstWhere('code', 'DD') ?? $branches->first();

        $salesUsers = User::role('sales_consultant')->get();
        if ($salesUsers->isEmpty()) {
            $salesUsers = User::all();
        }
        $saleVu = $salesUsers->firstWhere('email', 'levanvu@menglish.edu.vn') ?? $salesUsers->first();
        $saleMai = $salesUsers->firstWhere('email', 'tranmaia@menglish.edu.vn') ?? ($salesUsers->count() > 1 ? $salesUsers->get(1) : $saleVu);
        $saleThinh = $salesUsers->firstWhere('email', 'hoangthinh@menglish.edu.vn') ?? ($salesUsers->count() > 2 ? $salesUsers->get(2) : $saleVu);

        $adminUser = User::first();

        $customersData = [
            // Stage: New
            [
                'code' => 'KH-00281',
                'name' => 'Vũ Minh Đức',
                'phone' => '0987 111 222',
                'email' => 'duc.vu@gmail.com',
                'dob' => '2004-05-12',
                'gender' => 'Nam',
                'address' => 'Số 12 Dịch Vọng Hậu, Cầu Giấy, Hà Nội',
                'branch_id' => $branchCG?->id,
                'course_interest' => 'IELTS 6.5 Intensive',
                'source' => 'Facebook Ads',
                'assigned_user_id' => $saleVu?->id,
                'stage' => 'new',
                'deal_value' => 12500000,
                'created_at' => Carbon::now()->subDays(2),
                'notes' => 'Sinh viên năm 3 ĐHQG, cần bằng IELTS 6.5 trước tháng 12 để chuẩn bị hồ sơ ra trường.',
            ],
            [
                'code' => 'KH-00290',
                'name' => 'Trần Khánh Ly',
                'phone' => '0966 222 333',
                'email' => 'khanhly.tran@gmail.com',
                'dob' => '2005-08-14',
                'gender' => 'Nữ',
                'address' => '54 Nguyễn Phong Sắc, Cầu Giấy, Hà Nội',
                'branch_id' => $branchCG?->id,
                'course_interest' => 'IELTS 6.5 Intensive',
                'source' => 'Tiktok Organic',
                'assigned_user_id' => $saleMai?->id,
                'stage' => 'new',
                'deal_value' => 12500000,
                'created_at' => Carbon::now()->subDays(1),
                'notes' => 'Khách nhắn qua fanpage hỏi lịch khai giảng lớp tháng 9.',
            ],
            [
                'code' => 'KH-00291',
                'name' => 'Bùi Gia Huy',
                'phone' => '0977 444 888',
                'email' => 'giahuy.bui@gmail.com',
                'dob' => '2003-01-20',
                'gender' => 'Nam',
                'address' => '19 Kim Mã, Ba Đình, Hà Nội',
                'branch_id' => $branchBD?->id,
                'course_interest' => 'Giao tiếp Pro B1',
                'source' => 'Google Ads',
                'assigned_user_id' => $saleThinh?->id,
                'stage' => 'new',
                'deal_value' => 9500000,
                'created_at' => Carbon::now()->subHours(5),
                'notes' => 'Tìm kiếm khóa học giao tiếp công sở qua Google.',
            ],

            // Stage: Consulting
            [
                'code' => 'KH-00282',
                'name' => 'Nguyễn Bảo Trang',
                'phone' => '0912 444 555',
                'email' => 'trang.nb@gmail.com',
                'dob' => '2002-11-08',
                'gender' => 'Nữ',
                'address' => '25 Kim Mã, Ba Đình, Hà Nội',
                'branch_id' => $branchBD?->id,
                'course_interest' => 'Giao tiếp Pro B1',
                'source' => 'Tiktok Organic',
                'assigned_user_id' => $saleVu?->id,
                'stage' => 'consulting',
                'deal_value' => 9500000,
                'created_at' => Carbon::now()->subDays(4),
                'notes' => 'Đi làm văn phòng, mất gốc phát âm, muốn học ca tối thứ 2-4-6.',
            ],
            [
                'code' => 'KH-00292',
                'name' => 'Phạm Quốc Bảo',
                'phone' => '0981 555 777',
                'email' => 'quocbao.pham@gmail.com',
                'dob' => '2004-03-10',
                'gender' => 'Nam',
                'address' => '32 Chùa Láng, Đống Đa, Hà Nội',
                'branch_id' => $branchDD?->id,
                'course_interest' => 'IELTS 7.0 Master',
                'source' => 'Facebook Ads',
                'assigned_user_id' => $saleMai?->id,
                'stage' => 'consulting',
                'deal_value' => 16500000,
                'created_at' => Carbon::now()->subDays(6),
                'notes' => 'Đang phân vân giữa học Online hay Offline, Sale đã gửi lộ trình.',
            ],

            // Stage: Test Scheduled
            [
                'code' => 'KH-00283',
                'name' => 'Lê Hoàng Long',
                'phone' => '0936 666 777',
                'email' => 'long.lh@gmail.com',
                'dob' => '2005-02-20',
                'gender' => 'Nam',
                'address' => '88 Chùa Láng, Đống Đa, Hà Nội',
                'branch_id' => $branchDD?->id,
                'course_interest' => 'IELTS 7.0 Master',
                'source' => 'Google Ads',
                'assigned_user_id' => $saleThinh?->id,
                'stage' => 'test_scheduled',
                'deal_value' => 16500000,
                'appointment_at' => Carbon::now()->addDays(2)->format('Y-m-d 19:30:00'),
                'appointment_type' => 'offline',
                'created_at' => Carbon::now()->subDays(8),
                'notes' => 'Học sinh cấp 3 chuyên Amsterdam, đã có IELTS 6.0, mục tiêu 7.5 đi du học Úc.',
            ],
            [
                'code' => 'KH-00293',
                'name' => 'Đinh Hoàng Yến',
                'phone' => '0972 333 999',
                'email' => 'hoangyen.dinh@gmail.com',
                'dob' => '2003-12-05',
                'gender' => 'Nữ',
                'address' => '67 Cầu Giấy, Hà Nội',
                'branch_id' => $branchCG?->id,
                'course_interest' => 'IELTS 6.5 Intensive',
                'source' => 'Bạn bè giới thiệu',
                'assigned_user_id' => $saleVu?->id,
                'stage' => 'test_scheduled',
                'deal_value' => 12500000,
                'appointment_at' => Carbon::now()->addDays(1)->format('Y-m-d 15:00:00'),
                'appointment_type' => 'online',
                'created_at' => Carbon::now()->subDays(9),
                'notes' => 'Bạn của học viên Minh Anh giới thiệu qua test thử.',
            ],

            // Stage: Tested
            [
                'code' => 'KH-00284',
                'name' => 'Phạm Mai Anh',
                'phone' => '0978 888 999',
                'email' => 'maianh.pham@gmail.com',
                'dob' => '2003-09-15',
                'gender' => 'Nữ',
                'address' => '45 Trần Thái Tông, Cầu Giấy, Hà Nội',
                'branch_id' => $branchCG?->id,
                'course_interest' => 'IELTS 6.5 Intensive',
                'source' => 'Bạn bè giới thiệu',
                'assigned_user_id' => $saleVu?->id,
                'stage' => 'tested',
                'deal_value' => 12500000,
                'test_score' => '5.5 Overall (L: 5.5, R: 6.0, W: 5.0, S: 5.0)',
                'created_at' => Carbon::now()->subDays(12),
                'notes' => 'Đã test đầu vào đạt 5.5, đủ điều kiện vào khóa IELTS 6.5 Intensive.',
            ],
            [
                'code' => 'KH-00294',
                'name' => 'Trịnh Văn Quyết',
                'phone' => '0915 888 666',
                'email' => 'vanquyet.trinh@gmail.com',
                'dob' => '2000-07-22',
                'gender' => 'Nam',
                'address' => '12 Giảng Võ, Ba Đình, Hà Nội',
                'branch_id' => $branchBD?->id,
                'course_interest' => 'Giao tiếp Pro B2',
                'source' => 'Facebook Ads',
                'assigned_user_id' => $saleMai?->id,
                'stage' => 'tested',
                'deal_value' => 11500000,
                'test_score' => 'B1+ Phản xạ tốt',
                'created_at' => Carbon::now()->subDays(14),
                'notes' => 'Đã test khẩu ngữ với giáo viên, khuyên nên học lớp B2 Pro.',
            ],

            // Stage: Closing
            [
                'code' => 'KH-00285',
                'name' => 'Nguyễn Thị Thuỳ Dung',
                'phone' => '0982 345 678',
                'email' => 'thuydung.ng@gmail.com',
                'dob' => '2001-04-18',
                'gender' => 'Nữ',
                'address' => '102 Nguyễn Chí Thanh, Đống Đa, Hà Nội',
                'branch_id' => $branchDD?->id,
                'course_interest' => 'IELTS 6.5 Intensive',
                'source' => 'Facebook Ads',
                'assigned_user_id' => $saleVu?->id,
                'stage' => 'closing',
                'deal_value' => 12500000,
                'test_score' => '5.0 Overall',
                'created_at' => Carbon::now()->subDays(16),
                'notes' => 'Đang chờ phụ huynh chuyển khoản học phí đợt 1 để giữ chỗ lớp IE-2408.',
            ],
            [
                'code' => 'KH-00295',
                'name' => 'Vương Đình Huệ',
                'phone' => '0903 111 555',
                'email' => 'dinhhue.vuong@gmail.com',
                'dob' => '2004-09-18',
                'gender' => 'Nam',
                'address' => '78 Hoàng Hoa Thám, Ba Đình, Hà Nội',
                'branch_id' => $branchBD?->id,
                'course_interest' => 'IELTS 7.0 Master',
                'source' => 'Sự kiện Offline',
                'assigned_user_id' => $saleThinh?->id,
                'stage' => 'closing',
                'deal_value' => 16500000,
                'test_score' => '6.0 Overall',
                'created_at' => Carbon::now()->subDays(18),
                'notes' => 'Đã gửi mã VietQR cho phụ huynh qua Zalo, hẹn đóng phí trước 20h.',
            ],

            // Stage: Won (Chốt thành công)
            [
                'code' => 'KH-00286',
                'name' => 'Ngô Phương Linh',
                'phone' => '0918 999 111',
                'email' => 'phuonglinh.ngo@gmail.com',
                'dob' => '2003-07-24',
                'gender' => 'Nữ',
                'address' => '18 Hoàng Quốc Việt, Cầu Giấy, Hà Nội',
                'branch_id' => $branchCG?->id,
                'course_interest' => 'IELTS Combo 5.0 - 6.5',
                'source' => 'Sự kiện Offline',
                'assigned_user_id' => $saleVu?->id,
                'stage' => 'won',
                'deal_value' => 22000000,
                'created_at' => Carbon::now()->subDays(20),
                'notes' => 'Đã đóng 100% học phí gói Combo (22.000.000đ). Đã bàn giao lớp IE-2408.',
            ],
            [
                'code' => 'KH-00287',
                'name' => 'Dương Quốc Triệu',
                'phone' => '0909 333 444',
                'email' => 'trieu.dq@gmail.com',
                'dob' => '1999-12-05',
                'gender' => 'Nam',
                'address' => '36 Hai Bà Trưng, Hà Nội',
                'branch_id' => $branchBD?->id,
                'course_interest' => 'Giao tiếp Pro B2',
                'source' => 'Facebook Ads',
                'assigned_user_id' => $saleMai?->id,
                'stage' => 'won',
                'deal_value' => 11500000,
                'created_at' => Carbon::now()->subDays(22),
                'notes' => 'Đã đóng 100% học phí lớp Giao tiếp Pro B2. Đã xuất biên lai PT-2026-082.',
            ],
            [
                'code' => 'KH-00296',
                'name' => 'Lâm Thanh Hà',
                'phone' => '0989 654 321',
                'email' => 'thanhha.lam@gmail.com',
                'dob' => '2002-04-14',
                'gender' => 'Nữ',
                'address' => '142 Đội Cấn, Ba Đình, Hà Nội',
                'branch_id' => $branchBD?->id,
                'course_interest' => 'IELTS 6.5 Intensive',
                'source' => 'Website / Hotline',
                'assigned_user_id' => $saleThinh?->id,
                'stage' => 'won',
                'deal_value' => 12500000,
                'created_at' => Carbon::now()->subDays(24),
                'notes' => 'Đã đóng đủ học phí chuyển khoản. Xếp vào lớp tối 3-5-7.',
            ],
            [
                'code' => 'KH-00297',
                'name' => 'Đỗ Minh Tuấn',
                'phone' => '0913 222 777',
                'email' => 'minhtuan.do@gmail.com',
                'dob' => '2001-10-10',
                'gender' => 'Nam',
                'address' => '210 Xã Đàn, Đống Đa, Hà Nội',
                'branch_id' => $branchDD?->id,
                'course_interest' => 'IELTS 7.0 Master',
                'source' => 'Facebook Ads',
                'assigned_user_id' => $saleVu?->id,
                'stage' => 'won',
                'deal_value' => 16500000,
                'created_at' => Carbon::now()->subDays(25),
                'notes' => 'Học viên thanh toán toàn phần qua cổng thẻ tín dụng.',
            ],
            [
                'code' => 'KH-00298',
                'name' => 'Hoàng Thuý Vy',
                'phone' => '0975 111 333',
                'email' => 'thuyvy.hoang@gmail.com',
                'dob' => '2005-06-30',
                'gender' => 'Nữ',
                'address' => '90 Xuân Thủy, Cầu Giấy, Hà Nội',
                'branch_id' => $branchCG?->id,
                'course_interest' => 'Tiếng Anh Mất Gốc',
                'source' => 'Tiktok Organic',
                'assigned_user_id' => $saleMai?->id,
                'stage' => 'won',
                'deal_value' => 6900000,
                'created_at' => Carbon::now()->subDays(27),
                'notes' => 'Đã đóng học phí khóa mất gốc phát âm.',
            ],

            // Stage: Lost (Không chốt)
            [
                'code' => 'KH-00288',
                'name' => 'Lê Tuấn Hùng',
                'phone' => '0904 777 888',
                'email' => 'tuanhung.le@gmail.com',
                'dob' => '2004-10-30',
                'gender' => 'Nam',
                'address' => '72 Nguyễn Trãi, Thanh Xuân, Hà Nội',
                'branch_id' => $branchCG?->id,
                'course_interest' => 'IELTS Cấp tốc',
                'source' => 'Google Ads',
                'assigned_user_id' => $saleVu?->id,
                'stage' => 'lost',
                'deal_value' => 14000000,
                'lost_reason' => 'Học phí cao hơn ngân sách dự kiến của sinh viên',
                'created_at' => Carbon::now()->subDays(28),
                'notes' => 'Khách chê học phí cao, hẹn gọi lại đợt ưu đãi sinh viên tháng 9.',
            ],
            [
                'code' => 'KH-00299',
                'name' => 'Nguyễn Đình Khải',
                'phone' => '0932 444 111',
                'email' => 'dinhkhai.ng@gmail.com',
                'dob' => '2003-05-15',
                'gender' => 'Nam',
                'address' => '15 Láng Hạ, Đống Đa, Hà Nội',
                'branch_id' => $branchDD?->id,
                'course_interest' => 'IELTS 6.5 Intensive',
                'source' => 'Facebook Ads',
                'assigned_user_id' => $saleThinh?->id,
                'stage' => 'lost',
                'deal_value' => 12500000,
                'lost_reason' => 'Trùng lịch học trên trường đại học',
                'created_at' => Carbon::now()->subDays(29),
                'notes' => 'Khách bận thực tập tốt nghiệp không sắp xếp được lịch học.',
            ],
        ];

        foreach ($customersData as $data) {
            // Đánh dấu dữ liệu seed bằng tiền tố "# " để phân biệt với dữ liệu nhập tay / mockup JSON
            $data['name'] = '# ' . $data['name'];
            $customer = CrmCustomer::query()->updateOrCreate(
                ['code' => $data['code']],
                $data
            );

            // Seed initial activity history for customer
            CrmCustomerHistory::query()->firstOrCreate(
                [
                    'customer_id' => $customer->id,
                    'type' => 'system',
                    'content' => 'Tiếp nhận thông tin khách hàng từ kênh ' . $customer->source,
                ],
                [
                    'user_id' => $adminUser?->id,
                    'created_at' => $customer->created_at,
                ]
            );

            if ($customer->stage !== 'new') {
                CrmCustomerHistory::query()->firstOrCreate(
                    [
                        'customer_id' => $customer->id,
                        'type' => 'call',
                        'content' => 'Tư vấn lộ trình học ' . $customer->course_interest . ' và hẹn lịch kiểm tra trình độ.',
                    ],
                    [
                        'user_id' => $customer->assigned_user_id ?? $adminUser?->id,
                        'created_at' => $customer->created_at->copy()->addHours(2),
                    ]
                );
            }
        }
    }
}
