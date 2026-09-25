<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    /**
     * Run the database seeds. Idempotent: an chạy lại nhiều lần không tạo trùng.
     */
    public function run(): void
    {
        $branches = [
            ['code' => 'CG', 'name' => 'Chi nhánh Cầu Giấy', 'address' => 'Cầu Giấy, Hà Nội', 'phone' => '0243 555 0101'],
            ['code' => 'DD', 'name' => 'Chi nhánh Đống Đa', 'address' => 'Đống Đa, Hà Nội', 'phone' => '0243 555 0102'],
            ['code' => 'BD', 'name' => 'Chi nhánh Ba Đình', 'address' => 'Ba Đình, Hà Nội', 'phone' => '0243 555 0103'],
            ['code' => 'HBT', 'name' => 'Chi nhánh Hai Bà Trưng', 'address' => 'Hai Bà Trưng, Hà Nội', 'phone' => '0243 555 0104'],
            ['code' => 'Q1', 'name' => 'Chi nhánh Quận 1', 'address' => 'Quận 1, TP. Hồ Chí Minh', 'phone' => '0283 555 0201'],
            ['code' => 'Q7', 'name' => 'Chi nhánh Quận 7', 'address' => 'Quận 7, TP. Hồ Chí Minh', 'phone' => '0283 555 0202'],
            ['code' => 'TD', 'name' => 'Chi nhánh Thủ Đức', 'address' => 'Thủ Đức, TP. Hồ Chí Minh', 'phone' => '0283 555 0203'],
        ];

        foreach ($branches as $branch) {
            Branch::query()->updateOrCreate(
                ['code' => $branch['code']],
                $branch + ['is_active' => true]
            );
        }
    }
}
