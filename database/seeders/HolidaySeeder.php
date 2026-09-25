<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Holiday;
use Illuminate\Database\Seeder;

class HolidaySeeder extends Seeder
{
    /**
     * Run the database seeds. Dữ liệu mẫu theo đúng màn "Cấu hình Ngày nghỉ"
     * (epic-5/cau-hinh-ngay-nghi). Idempotent.
     */
    public function run(): void
    {
        $holidays = [
            [
                'code' => 'HOL-2026-001',
                'name' => 'Tết Nguyên Đán 2026',
                'start_date' => '2026-02-08',
                'end_date' => '2026-02-14',
                'is_system_wide' => true,
                'branches' => [],
            ],
            [
                'code' => 'HOL-2026-002',
                'name' => 'Ngày Giỗ Tổ Hùng Vương',
                'start_date' => '2026-04-18',
                'end_date' => '2026-04-18',
                'is_system_wide' => false,
                'branches' => ['Q1', 'Q7'],
            ],
            [
                'code' => 'HOL-2026-003',
                'name' => 'Ngày Giải phóng miền Nam',
                'start_date' => '2026-04-30',
                'end_date' => '2026-05-01',
                'is_system_wide' => true,
                'branches' => [],
            ],
        ];

        foreach ($holidays as $data) {
            $branchCodes = $data['branches'];
            unset($data['branches']);

            $holiday = Holiday::query()->updateOrCreate(['code' => $data['code']], $data);

            if (! empty($branchCodes)) {
                $branchIds = Branch::query()->whereIn('code', $branchCodes)->pluck('id');
                $holiday->branches()->sync($branchIds);
            }
        }
    }
}
