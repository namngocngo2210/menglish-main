<?php

namespace Database\Seeders;

use App\Models\SystemCategory;
use App\Support\Roles;
use Illuminate\Database\Seeder;

class SystemCategorySeeder extends Seeder
{
    /**
     * Run the database seeds. Dữ liệu tham chiếu theo đúng các tab trong màn
     * "Quản lý Danh mục hệ thống" (epic-5/quan-ly-danh-muc-he-thong). Idempotent.
     */
    public function run(): void
    {
        $groups = [
            SystemCategory::TYPE_LEAD_SOURCE => [
                ['code' => 'SRC_01', 'name' => 'Facebook Ads'],
                ['code' => 'SRC_02', 'name' => 'TikTok Organic'],
                ['code' => 'SRC_03', 'name' => 'Giới thiệu (Referral)'],
                ['code' => 'SRC_04', 'name' => 'Google Search'],
                ['code' => 'SRC_05', 'name' => 'Youtube Review'],
                ['code' => 'SRC_06', 'name' => 'Landing page'],
                ['code' => 'SRC_07', 'name' => 'Vãng lai (Walk-in)'],
            ],
            SystemCategory::TYPE_LOST_REASON => [
                ['code' => 'LOST_01', 'name' => 'Học phí không phù hợp ngân sách'],
                ['code' => 'LOST_02', 'name' => 'Vị trí / lịch học không thuận tiện'],
                ['code' => 'LOST_03', 'name' => 'Đã đăng ký trung tâm khác'],
                ['code' => 'LOST_04', 'name' => 'Không phản hồi / mất liên lạc'],
                ['code' => 'LOST_05', 'name' => 'Đổi ý không đăng ký'],
            ],
            SystemCategory::TYPE_POSITION => [
                // role_code: vai trò (phân quyền) của chức vụ; đủ 10 vai trò nhân sự (không có Học viên).
                ['code' => 'POS_01', 'name' => 'Admin Hệ thống', 'role_code' => Roles::ADMIN],
                ['code' => 'POS_02', 'name' => 'Quản lý cấp cao', 'role_code' => Roles::MANAGER],
                ['code' => 'POS_03', 'name' => 'Tư vấn viên', 'role_code' => Roles::SALES_CONSULTANT],
                ['code' => 'POS_04', 'name' => 'Học vụ', 'role_code' => Roles::ACADEMIC_STAFF],
                ['code' => 'POS_05', 'name' => 'Kế toán', 'role_code' => Roles::ACCOUNTANT],
                ['code' => 'POS_06', 'name' => 'Giáo viên Tiếng Anh', 'role_code' => Roles::TEACHER_FULLTIME],
                ['code' => 'POS_07', 'name' => 'Giáo viên nước ngoài', 'role_code' => Roles::FOREIGN_TEACHER],
                ['code' => 'POS_08', 'name' => 'Trợ giảng', 'role_code' => Roles::ASSISTANT],
                ['code' => 'POS_09', 'name' => 'Trưởng học thuật', 'role_code' => Roles::ACADEMIC_LEAD],
                ['code' => 'POS_10', 'name' => 'Giáo viên bán thời gian', 'role_code' => Roles::TEACHER_PARTTIME],
            ],
            SystemCategory::TYPE_FINE_LEVEL => [
                ['code' => 'FINE_01', 'name' => 'Nhẹ - 100.000đ'],
                ['code' => 'FINE_02', 'name' => 'Trung bình - 200.000đ'],
                ['code' => 'FINE_03', 'name' => 'Nặng - 500.000đ'],
            ],
            SystemCategory::TYPE_TA_CHECKLIST_GROUP => [
                ['code' => 'TAG_01', 'name' => 'Chuẩn bị lớp'],
                ['code' => 'TAG_02', 'name' => 'Trong buổi học'],
                ['code' => 'TAG_03', 'name' => 'Sau buổi học'],
            ],
        ];

        foreach ($groups as $type => $items) {
            foreach ($items as $index => $item) {
                SystemCategory::query()->updateOrCreate(
                    ['type' => $type, 'code' => $item['code']],
                    ['name' => $item['name'], 'sort_order' => $index + 1, 'is_active' => true] + (isset($item['role_code']) ? ['role_code' => $item['role_code']] : [])
                );
            }
        }
    }
}
