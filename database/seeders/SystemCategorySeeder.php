<?php

namespace Database\Seeders;

use App\Models\SystemCategory;
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
                ['code' => 'POS_01', 'name' => 'Admin Hệ thống'],
                ['code' => 'POS_02', 'name' => 'Quản lý cấp cao'],
                ['code' => 'POS_03', 'name' => 'Tư vấn viên'],
                ['code' => 'POS_04', 'name' => 'Học vụ'],
                ['code' => 'POS_05', 'name' => 'Kế toán'],
                ['code' => 'POS_06', 'name' => 'Giáo viên Tiếng Anh'],
                ['code' => 'POS_07', 'name' => 'Giáo viên nước ngoài'],
                ['code' => 'POS_08', 'name' => 'Trợ giảng'],
            ],
            SystemCategory::TYPE_FINE_LEVEL => [
                ['code' => 'FINE_01', 'name' => 'Nhẹ - 100.000đ'],
                ['code' => 'FINE_02', 'name' => 'Trung bình - 200.000đ'],
                ['code' => 'FINE_03', 'name' => 'Nặng - 500.000đ'],
            ],
        ];

        foreach ($groups as $type => $items) {
            foreach ($items as $index => $item) {
                SystemCategory::query()->updateOrCreate(
                    ['type' => $type, 'code' => $item['code']],
                    ['name' => $item['name'], 'sort_order' => $index + 1, 'is_active' => true]
                );
            }
        }
    }
}
