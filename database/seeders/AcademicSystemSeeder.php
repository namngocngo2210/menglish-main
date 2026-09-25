<?php

namespace Database\Seeders;

use App\Models\AcademicRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class AcademicSystemSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('seeders/academic_seeds_58.json');
        if (!File::exists($jsonPath)) {
            // Auto regenerate if file does not exist
            if (File::exists(database_path('seeders/generate_academic_seeds.php'))) {
                require_once database_path('seeders/generate_academic_seeds.php');
            }
        }

        if (!File::exists($jsonPath)) {
            $this->command->error("File academic_seeds_58.json không tồn tại!");
            return;
        }

        $allSeeds = json_decode(File::get($jsonPath), true);
        if (!$allSeeds) {
            $this->command->error("Không thể giải mã JSON từ academic_seeds_58.json!");
            return;
        }

        $insertedCount = 0;
        $screenCount = 0;

        foreach ($allSeeds as $screenKey => $records) {
            $screenCount++;
            foreach ($records as $item) {
                AcademicRecord::updateOrCreate(
                    [
                        'screen_key' => $item['screen_key'],
                        'record_code' => $item['record_code'],
                    ],
                    [
                        'module' => $item['module'],
                        'title' => $item['title'],
                        'status' => $item['status'],
                        'is_seed' => true,
                        'data' => $item['data'],
                    ]
                );
                $insertedCount++;
            }
        }

        $this->command->info("Đã nạp thành công {$insertedCount} bản ghi mẫu (SEED) cho {$screenCount}/58 màn hình (5 bản ghi/màn) vào bảng academic_records!");
    }
}
