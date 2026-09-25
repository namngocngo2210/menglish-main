<?php

namespace Database\Seeders;

use App\Models\MerchandiseItem;
use Illuminate\Database\Seeder;

class MerchandiseItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'code' => 'BOOK-CAM-S1',
                'name' => 'Bộ Giáo trình Cambridge Primary Stage 1',
                'category' => 'book',
                'unit' => 'Bộ',
                'price' => 300000,
                'cost_price' => 210000,
                'stock_quantity' => 85,
                'is_active' => true,
                'description' => 'Bộ giáo trình chuẩn Cambridge dành cho học viên cấp độ 1 kèm đĩa nghe audio.',
            ],
            [
                'code' => 'BOOK-CAM-S2',
                'name' => 'Bộ Giáo trình Cambridge Primary Stage 2',
                'category' => 'book',
                'unit' => 'Bộ',
                'price' => 320000,
                'cost_price' => 220000,
                'stock_quantity' => 120,
                'is_active' => true,
                'description' => 'Bộ sách chính khóa Cambridge cấp độ 2 phát triển 4 kỹ năng.',
            ],
            [
                'code' => 'BOOK-CAM-S3',
                'name' => 'Bộ Giáo trình Cambridge Stage 3',
                'category' => 'book',
                'unit' => 'Bộ',
                'price' => 350000,
                'cost_price' => 240000,
                'stock_quantity' => 95,
                'is_active' => true,
                'description' => 'Bộ sách giáo trình chuẩn Cambridge Stage 3 (Learner\'s Book + App QR).',
            ],
            [
                'code' => 'WB-CAM-S3',
                'name' => 'Sách bài tập Workbook Cambridge Stage 3',
                'category' => 'workbook',
                'unit' => 'Cuốn',
                'price' => 150000,
                'cost_price' => 90000,
                'stock_quantity' => 150,
                'is_active' => true,
                'description' => 'Sách bài tập thực hành bổ trợ ngữ pháp và từ vựng theo buổi học.',
            ],
            [
                'code' => 'BOOK-IELTS-FD',
                'name' => 'Bộ Giáo trình IELTS Foundation Master',
                'category' => 'book',
                'unit' => 'Bộ',
                'price' => 450000,
                'cost_price' => 300000,
                'stock_quantity' => 60,
                'is_active' => true,
                'description' => 'Giáo trình luyện thi IELTS Foundation bản quyền MEnglish biên soạn.',
            ],
            [
                'code' => 'WB-IELTS-FD',
                'name' => 'Sách bài tập IELTS Intensive Practice',
                'category' => 'workbook',
                'unit' => 'Cuốn',
                'price' => 180000,
                'cost_price' => 110000,
                'stock_quantity' => 75,
                'is_active' => true,
                'description' => 'Tập luyện đề và bài tập về nhà kỹ năng Writing & Reading.',
            ],
            [
                'code' => 'UNI-POLO-S',
                'name' => 'Áo Polo Đồng phục MEnglish (Size S)',
                'category' => 'uniform',
                'unit' => 'Chiếc',
                'price' => 200000,
                'cost_price' => 130000,
                'stock_quantity' => 40,
                'is_active' => true,
                'description' => 'Chất liệu thun cá sấu cotton 4 chiều thoáng mát, màu cam nhận diện thương hiệu.',
            ],
            [
                'code' => 'UNI-POLO-M',
                'name' => 'Áo Polo Đồng phục MEnglish (Size M)',
                'category' => 'uniform',
                'unit' => 'Chiếc',
                'price' => 200000,
                'cost_price' => 130000,
                'stock_quantity' => 65,
                'is_active' => true,
                'description' => 'Chất liệu thun cá sấu cotton 4 chiều thoáng mát, màu cam nhận diện thương hiệu.',
            ],
            [
                'code' => 'UNI-POLO-L',
                'name' => 'Áo Polo Đồng phục MEnglish (Size L)',
                'category' => 'uniform',
                'unit' => 'Chiếc',
                'price' => 200000,
                'cost_price' => 130000,
                'stock_quantity' => 50,
                'is_active' => true,
                'description' => 'Chất liệu thun cá sấu cotton 4 chiều thoáng mát, màu cam nhận diện thương hiệu.',
            ],
            [
                'code' => 'BAG-ME-PRO',
                'name' => 'Balo học sinh MEnglish Chống gù',
                'category' => 'backpack',
                'unit' => 'Chiếc',
                'price' => 250000,
                'cost_price' => 160000,
                'stock_quantity' => 35,
                'is_active' => true,
                'description' => 'Balo chất liệu chống thấm nước, đệm lưng chống gù cho học sinh tiểu học và THCS.',
            ],
            [
                'code' => 'BAG-ME-STD',
                'name' => 'Balo dây rút thể thao MEnglish',
                'category' => 'backpack',
                'unit' => 'Chiếc',
                'price' => 120000,
                'cost_price' => 70000,
                'stock_quantity' => 110,
                'is_active' => true,
                'description' => 'Túi rút đa năng đựng tài liệu học tập nhẹ nhàng, thời trang.',
            ],
            [
                'code' => 'GIFT-BOT-01',
                'name' => 'Bình giữ nhiệt MEnglish Eco 500ml',
                'category' => 'gift',
                'unit' => 'Cái',
                'price' => 160000,
                'cost_price' => 95000,
                'stock_quantity' => 80,
                'is_active' => true,
                'description' => 'Inox 304 giữ nhiệt 12h, nắp hiển thị nhiệt độ thông minh.',
            ],
            [
                'code' => 'STN-NOTE-01',
                'name' => 'Combo Sổ tay từ vựng & Bút bi MEnglish',
                'category' => 'gift',
                'unit' => 'Bộ',
                'price' => 60000,
                'cost_price' => 30000,
                'stock_quantity' => 200,
                'is_active' => true,
                'description' => 'Sổ lò xo bìa cứng thiết kế kẻ cột học từ vựng Spaced Repetition.',
            ],
        ];

        foreach ($items as $item) {
            MerchandiseItem::updateOrCreate(
                ['code' => $item['code']],
                $item
            );
        }
    }
}
