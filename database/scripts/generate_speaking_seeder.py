import json

speaking_tests = [
    {
        'code': 'TEST-SPEAKING-PRE-G1',
        'title': 'Đề Test Nói Đánh Giá Năng Lực Pre-school Đến Lớp 1 (Pre-Starters)',
        'target_level': 'Pre-Starters (Mầm non & Tiền tiểu học)',
        'duration_minutes': 15,
        'is_preset': True,
        'questions': [
            {
                'id': 1,
                'skill': 'speaking',
                'type': 'speaking_prompt',
                'title': 'Part I: Introduction - Personal Information',
                'points': 2,
                'cue_points': "1. What's your name? How are you today? (0.5 điểm)\n2. How old are you? What class are you in? (1.0 điểm)\n3. What is your school name? (0.5 điểm)",
                'explanation': 'Giáo viên phỏng vấn chào hỏi, tạo không khí thân thiện và đánh giá phản xạ trả lời thông tin cá nhân cơ bản.'
            },
            {
                'id': 2,
                'skill': 'speaking',
                'type': 'speaking_prompt',
                'title': 'Part II - Picture 1: Zoo',
                'points': 1.5,
                'image_url': '/uploads/tests/speaking/SPEAKING-PRE-G1/image1.png',
                'cue_points': '• Point to the picture: Where is this? (It is a zoo / Zoo)\n• What animals can you see in the zoo?',
                'explanation': 'Đánh giá nhận diện từ vựng chủ đề Zoo qua tranh.'
            },
            {
                'id': 3,
                'skill': 'speaking',
                'type': 'speaking_prompt',
                'title': 'Part II - Picture 2: Elephant',
                'points': 1.5,
                'image_url': '/uploads/tests/speaking/SPEAKING-PRE-G1/image2.png',
                'cue_points': '• What animal is this? (Elephant)\n• Is it big or small? What color is it?',
                'explanation': 'Đánh giá từ vựng con vật và tính từ mô tả cơ bản.'
            },
            {
                'id': 4,
                'skill': 'speaking',
                'type': 'speaking_prompt',
                'title': 'Part II - Picture 3: Colors (Blue)',
                'points': 1.5,
                'image_url': '/uploads/tests/speaking/SPEAKING-PRE-G1/image3.png',
                'cue_points': '• What color is this? (Blue)\n• Can you point to something blue in the room?',
                'explanation': 'Đánh giá nhận diện màu sắc.'
            },
            {
                'id': 5,
                'skill': 'speaking',
                'type': 'speaking_prompt',
                'title': 'Part II - Picture 4: Family & Friends',
                'points': 3.5,
                'image_url': '/uploads/tests/speaking/SPEAKING-PRE-G1/image4.png',
                'cue_points': '1. Who are they? Family or Friends? (1.5 điểm)\n2. How many people are there in your family? (1.0 điểm)\n3. Point to the people: Who is this? (Father / Mother / Brother / Sister / Baby...) (1.0 điểm)',
                'explanation': 'Đánh giá từ vựng chủ đề Gia đình và kỹ năng đếm số lượng người.'
            }
        ]
    },
    {
        'code': 'TEST-SPEAKING-G1-G3',
        'title': 'Đề Test Nói Đánh Giá Năng Lực Lớp 1 Đến Lớp 3 (Starters)',
        'target_level': 'Tiểu học Starters (Lớp 1 - Lớp 3)',
        'duration_minutes': 15,
        'is_preset': True,
        'questions': [
            {
                'id': 1,
                'skill': 'speaking',
                'type': 'speaking_prompt',
                'title': 'Part I: Introduction & Neighborhood',
                'points': 2,
                'cue_points': "1. What's your name? How are you today? (0.5 điểm)\n2. How old are you? What grade are you in? (0.5 điểm)\n3. Where do you live? Can you describe your neighborhood? (1.0 điểm)",
                'explanation': 'Đánh giá khả năng giới thiệu bản thân và mô tả nơi sinh sống bằng câu ngắn.'
            },
            {
                'id': 2,
                'skill': 'speaking',
                'type': 'speaking_prompt',
                'title': 'Part II - Picture 1: Classroom Scene',
                'points': 4,
                'image_url': '/uploads/tests/speaking/SPEAKING-G1-G3/image1.jpg',
                'cue_points': "1. Point to people: Who's this? (Teacher or student) / Where are they? (In the classroom)\n2. Point to objects: What is this? (Book / Pen / Clock / Backpack)\n3. Point to the girl writing: How is she? Is she sad?\n4. Point to the boy standing: What color shirt is he wearing?",
                'explanation': 'Đánh giá nhận diện đồ vật trong lớp học, hành động và màu sắc trang phục.'
            },
            {
                'id': 3,
                'skill': 'speaking',
                'type': 'speaking_prompt',
                'title': 'Part II - Picture 2: Park & Animals',
                'points': 4,
                'image_url': '/uploads/tests/speaking/SPEAKING-G1-G3/image2.png',
                'cue_points': '1. How many people are there? (Point and count)\n2. What color are the balloons? (Red, orange, green...)\n3. What animal is this? (Elephant / Dog / Duck...)\n4. How is the weather? (Is it sunny, rainy, or cloudy?)',
                'explanation': 'Đánh giá số đếm, màu sắc, con vật và thời tiết.'
            }
        ]
    },
    {
        'code': 'TEST-SPEAKING-G3-G4',
        'title': 'Đề Test Nói Đánh Giá Năng Lực Lớp 3 Đến Lớp 4 (Movers)',
        'target_level': 'Tiểu học Movers (Lớp 3 - Lớp 4)',
        'duration_minutes': 15,
        'is_preset': True,
        'questions': [
            {
                'id': 1,
                'skill': 'speaking',
                'type': 'speaking_prompt',
                'title': 'Part I: Free Time & Hobbies',
                'points': 3,
                'cue_points': '1. What do you like to do in your free time? (1.0 điểm)\n2. Do you have any hobbies? Can you tell me about one of them? (1.0 điểm)\n3. What do you usually do after school? (1.0 điểm)',
                'explanation': 'Đánh giá khả năng diễn đạt sở thích và hoạt động sau giờ học với cấu trúc thì hiện tại đơn.'
            },
            {
                'id': 2,
                'skill': 'speaking',
                'type': 'speaking_prompt',
                'title': 'Part II - Picture 1: Find the Differences (Bedroom Scene)',
                'points': 4,
                'image_url': '/uploads/tests/speaking/SPEAKING-G3-G4/image1.png',
                'cue_points': "1. Can you find any differences between the two pictures?\n2. How many differences can you see? (Pointing to differences)\n3. Where is the laptop on the bed?\n4. What color is the girl's towel in each picture?\n5. Are her socks and feet the same in both pictures?",
                'explanation': 'Đánh giá kỹ năng so sánh 2 bức tranh (In picture A... but in picture B...).'
            },
            {
                'id': 3,
                'skill': 'speaking',
                'type': 'speaking_prompt',
                'title': 'Part II - Picture 2: Picture Story (Cinema Tickets)',
                'points': 3,
                'image_url': '/uploads/tests/speaking/SPEAKING-G3-G4/image2.png',
                'cue_points': '• Picture 1: Where are the family members? What is Mum giving to Charlie and Jack? How do they feel?\n• Picture 2: What are they doing now? What happened to one of the tickets?\n• Picture 3: How does Mum feel? Why are they worried?\n• Picture 4: What are Charlie and Jack holding now? Can they go to the cinema in the end?',
                'explanation': 'Đánh giá khả năng kể chuyện theo chuỗi 4 tranh liên hoàn (Picture Storytelling).'
            }
        ]
    },
    {
        'code': 'TEST-SPEAKING-G4-G6',
        'title': 'Đề Test Nói Đánh Giá Năng Lực Lớp 4 Đến Lớp 6 (Flyers / KET)',
        'target_level': 'Tiểu học - THCS (Flyers / KET)',
        'duration_minutes': 15,
        'is_preset': True,
        'questions': [
            {
                'id': 1,
                'skill': 'speaking',
                'type': 'speaking_prompt',
                'title': 'Part I: Self-Introduction & Daily Routine',
                'points': 3,
                'cue_points': '1. Can you introduce yourself? (Name, age, address) (0.5 điểm)\n2. Tell me about your school (Grade, what you like about school) (0.5 điểm)\n3. What is your favorite subject in school? Why? (1.0 điểm)\n4. Can you describe a typical day in your life? What time do you wake up? (1.0 điểm)',
                'explanation': 'Đánh giá khả năng trả lời trôi chảy các câu hỏi mở rộng về trường học, môn học và thói quen sinh hoạt.'
            },
            {
                'id': 2,
                'skill': 'speaking',
                'type': 'speaking_prompt',
                'title': 'Part II - Picture 1: Find the Differences (Studio / Party Scene)',
                'points': 5,
                'image_url': '/uploads/tests/speaking/SPEAKING-G4-G6/image1.png',
                'cue_points': "1. How many differences can you find between Picture A and Picture B?\n2. Cake on the table (square vs round)\n3. Man holding tray (carrying cups vs cake)\n4. TV host's shirt (striped vs plain)\n5. Picture on the wall (sunny vs rainy)\n6. Boxes on the floor\n7. Woman at the door & woman at the desk",
                'explanation': 'Đánh giá kỹ năng quan sát chi tiết và vốn từ vựng miêu tả hành động, trạng thái phức tạp.'
            },
            {
                'id': 3,
                'skill': 'speaking',
                'type': 'speaking_prompt',
                'title': 'Part II - Picture 2: Picture Story (The Cat on the Tree)',
                'points': 2,
                'image_url': '/uploads/tests/speaking/SPEAKING-G4-G6/image2.png',
                'cue_points': '• Picture 1: Where are the teacher and students? What are Nick and Anna doing?\n• Picture 2: What are they looking at through the window?\n• Picture 3: Where is the teacher going? Why is she leaving the classroom?\n• Picture 4: What is the teacher doing to help the cat in the tree?\n• Picture 5: What is the teacher holding when she returns? How do the students feel?',
                'explanation': 'Đánh giá khả năng tường thuật câu chuyện gồm 5 phân cảnh với thì quá khứ hoặc hiện tại tiếp diễn.'
            }
        ]
    }
]

json_str = json.dumps(speaking_tests, ensure_ascii=False, indent=8)

php_code = f"""<?php

namespace Database\Seeders;

use App\Models\PlacementTest;
use Illuminate\Database\Seeder;

class SpeakingTestsSeeder extends Seeder
{{
    public function run(): void
    {{
        $testsJson = <<<'JSON'
{json_str}
JSON;

        $tests = json_decode($testsJson, true);

        foreach ($tests as $t) {{
            PlacementTest::updateOrCreate(
                ['code' => $t['code']],
                [
                    'title' => $t['title'],
                    'target_level' => $t['target_level'],
                    'duration_minutes' => $t['duration_minutes'],
                    'questions_count' => count($t['questions']),
                    'questions' => $t['questions'],
                    'is_active' => true,
                    'is_preset' => true,
                ]
            );
        }}
    }}
}}
"""

with open('database/seeders/SpeakingTestsSeeder.php', 'w', encoding='utf-8') as f:
    f.write(php_code)

print("Generated SpeakingTestsSeeder.php successfully!")
