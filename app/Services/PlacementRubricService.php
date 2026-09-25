<?php

namespace App\Services;

/**
 * Thang điểm test đầu vào theo khối lớp (BA chốt Q2 — 25/09/2026).
 *
 * Nguồn: "Thang điểm + hướng dẫn nhận xét" (ui-full-tinh-nang-menglish/Thang điểm + hướng dẫn nhận xét.html,
 * bản Excel gốc public/uploads/2026/thang-diem-danh-gia/…xlsx, trích xuất database/scripts/rubric_sheet2.json).
 *
 * - Chấm theo khối lớp: Tổng = Listening + Reading & Writing + Speaking (điểm thô theo thang của từng khối).
 * - Tra tổng điểm → lớp đề xuất (de_xuat_lop). Học vụ được chọn lại lớp khi đánh giá.
 * - Nhận xét từng kỹ năng gợi ý theo băng điểm; người chấm sửa được.
 * - Speaking luôn nhập tay.
 * - Không còn quy đổi "trung bình 4 kỹ năng thang 10 → A1–C1 / CEFR".
 *
 * Ngưỡng băng điểm theo bộ mô phỏng trong file HTML (cận dưới của băng kế tiếp thuộc băng kế tiếp;
 * điểm dưới băng thấp nhất vẫn nhận nhận xét / lớp của băng thấp nhất).
 */
class PlacementRubricService
{
    /** Khối không có thang điểm: học viên lớn (lớp 5–9, IELTS, người đi làm) và mầm non (chỉ test nói). */
    public const MANUAL_GROUP = 'khac';

    public const SKILLS = [
        'listening' => 'Nghe (Listening)',
        'reading_writing' => 'Đọc & Viết (Reading & Writing)',
        'speaking' => 'Nói (Speaking)',
    ];

    /** Thang tạm cho khối chưa có rubric: mỗi kỹ năng 0–10, không quy đổi ra lớp. */
    public const MANUAL_MAX = ['listening' => 10, 'reading_writing' => 10, 'speaking' => 10];

    private const NO_RUBRIC_NOTICE = 'Chưa có thang điểm — Học thuật chọn lớp thủ công';

    /**
     * bands: băng điểm kỹ năng tăng dần theo cận dưới 'min'. placements: băng tổng điểm → lớp đề xuất ('lt' / 'lte' / không cận).
     *
     * @var array<string, array<string, mixed>>
     */
    private const RUBRICS = [
        'khoi_1_2' => [
            'label' => 'Khối 1 - 2',
            'level' => 'Starters',
            'max' => ['listening' => 10, 'reading_writing' => 15, 'speaking' => 10],
            'bands' => [
                'listening' => [
                    ['min' => 0, 'range' => '2 - 4', 'text' => 'Con bắt đầu hình thành kĩ năng nghe cơ bản, con nhận diện được một số từ vựng đơn giản thông qua hình vẽ, các bài nghe nắm bắt thông tin để điền con chưa xử lí được, chưa nhận diện được các loại câu hỏi để tập trung tìm thông tin.'],
                    ['min' => 5, 'range' => '5 - 7', 'text' => 'Con đã có kĩ năng nghe cơ bản, con nghe và nhận diện được các từ vựng đơn giản thông qua tranh, bước đầu hình thành được kĩ năng nghe hội thoại, nhận diện một số từ key word, xác định được nội dung câu hỏi nhưng chưa theo được tốc độ của bài nghe còn bỏ lỡ thông tin.'],
                    ['min' => 8, 'range' => '8 - 10', 'text' => 'Con nghe tốt, nắm được hệ thống từ vựng của các chủ đề cơ bản xung quanh cuộc sống, nhận diện được từ vựng qua tranh và qua thông tin của bài hội thoại.'],
                ],
                'reading_writing' => [
                    ['min' => 0, 'range' => '1 - 5', 'text' => 'Con chưa có nền từ tốt, nhận diện một số từ đơn cơ bản, chưa hình thành kĩ năng nhớ từ vựng, con đọc câu chưa phân biệt được đúng sai.'],
                    ['min' => 6, 'range' => '6 - 10', 'text' => 'Con nhận diện được cơ bản một số từ vựng, chưa nhớ chính tả từ nên còn nhầm lẫn từ vựng. Con đọc câu chưa hiểu hết nội dung nhưng nhận diện được một số câu để phân biệt đúng sai.'],
                    ['min' => 11, 'range' => '11 - 15', 'text' => 'Con có vốn từ cơ bản về hệ thống các từ vựng chủ đề xung quanh, con nhớ chính tả từ, nắm được nội dung câu đơn giản. Kĩ năng xử lí bài tập Khá / Tốt.'],
                ],
                'speaking' => [
                    ['min' => 0, 'range' => '2 - 4', 'text' => 'Con chưa hình thành kĩ năng nghe nói cơ bản, chưa nhận diện được các câu hỏi cơ bản, chỉ nắm bắt được 1,2 câu hỏi thông tin cá nhân đơn giản nhất.'],
                    ['min' => 5, 'range' => '5 - 7', 'text' => 'Con đã có kĩ năng nghe nói cơ bản, có nhận diện được một số câu hỏi cơ bản theo tranh cô hỏi, con tự tin / không tự tin. Con phát âm tốt / chưa tốt. Một số câu hỏi với từ để hỏi khó hơn con chưa nhận diện được.'],
                    ['min' => 8, 'range' => '8 - 10', 'text' => 'Con nói trôi chảy, nắm bắt được nội dung câu hỏi các từ để hỏi cơ bản level Starters từ cô giáo, từ vựng các chủ đề quen thuộc con nắm khá tốt, con có thể / chưa trả lời được cả câu; khá tự tin và trả lời tốt.'],
                ],
            ],
            // < 10 | 10 - 15 | 16 - 25 | (> 25: bộ mô phỏng trong file HTML)
            'placements' => [
                ['lt' => 10, 'range' => '< 10', 'class' => 'PRE STARTERS (FAM 0)'],
                ['lte' => 15, 'range' => '10 - 15', 'class' => 'STARTERS (FAM 1 _ Ở NHỮNG BÀI ĐẦU)'],
                ['lte' => 25, 'range' => '16 - 25', 'class' => 'STARTERS (FAM 1 _ TỪ BÀI 5 - 10)'],
                ['range' => '> 25', 'class' => 'STARTERS (FAM 1 _ NÂNG CAO)'],
            ],
        ],
        'khoi_2_3' => [
            'label' => 'Khối 2 lên 3',
            'level' => 'Starters',
            'max' => ['listening' => 15, 'reading_writing' => 15, 'speaking' => 10],
            'bands' => [
                'listening' => [
                    ['min' => 0, 'range' => '1 - 6', 'text' => 'Con có kĩ năng nghe cơ bản, con nghe và nhận diện được một số các tình huống bài nghe cơ bản, nhận diện được key words và chọn được một số câu trả lời đúng, chưa quen với các dạng bài nghe đa dạng.'],
                    ['min' => 6, 'range' => '6 - 10', 'text' => 'Con có kĩ năng nghe trung bình khá, con nghe và nhận diện được các thông tin trong các bài nghe nhận diện. Con phân biệt được các câu hỏi và nắm được thông tin để điền cơ bản. Con nghe thông tin đơn nhất, 1 chiều, bị nhầm lẫn với các thông tin đa chiều, thông tin gây nhiễu.'],
                    ['min' => 11, 'range' => '11 - 15', 'text' => 'Con nghe khá, quen với một số các dạng nghe cơ bản, nắm bắt được các thông tin trong bài nghe. Con phân biệt được các thông tin đa chiều, thông tin gây nhiễu, con nhận diện được thông tin để điền. Cần chú ý hơn phần nối tranh / viết thông tin / nghe hội thoại chọn tranh.'],
                ],
                'reading_writing' => [
                    ['min' => 0, 'range' => '1 - 5', 'text' => 'Con chưa có nền từ tốt, nhận diện một số từ đơn cơ bản, chưa hình thành kĩ năng nhớ từ vựng, con đọc câu chưa phân biệt được đúng sai, gặp khó khăn trong các kĩ năng đọc điền từ. Nền ngữ pháp yếu nên chưa sắp xếp được câu.'],
                    ['min' => 6, 'range' => '6 - 10', 'text' => 'Con nhận diện được cơ bản một số từ vựng. Con chưa hiểu hết ý nghĩa câu mà nhận diện đúng / sai dựa vào key word trong mỗi câu. Kĩ năng đọc điền từ con chưa xử lí linh hoạt. Ngữ pháp cần trau dồi để xử lí những bài ngữ pháp nhưng sắp xếp câu.'],
                    ['min' => 11, 'range' => '11 - 15', 'text' => 'Con có nền từ khá tốt, con đọc hiểu những câu cơ bản, xử lí bài tập đúng sai linh hoạt. Con hình thành kĩ năng đọc hiểu cơ bản khá, thỉnh thoảng còn sai một vài từ. Kĩ năng xử lí bài tập kĩ năng đọc khá / tốt. Ngữ pháp con nắm cơ bản / tốt. Xử lí bài sắp xếp câu linh hoạt / còn chưa linh hoạt.'],
                ],
                'speaking' => [
                    ['min' => 0, 'range' => '2 - 4', 'text' => 'Con chưa hình thành kĩ năng nghe nói cơ bản, chưa nhận diện được các câu hỏi cơ bản, chỉ nắm bắt được 1,2 câu hỏi thông tin cá nhân đơn giản nhất.'],
                    ['min' => 5, 'range' => '5 - 7', 'text' => 'Con đã có kĩ năng nghe nói cơ bản, có nhận diện được một số câu hỏi cơ bản theo tranh cô hỏi, con tự tin / không tự tin. Con phát âm tốt / chưa tốt. Một số câu hỏi với từ để hỏi khó hơn con chưa nhận diện được.'],
                    ['min' => 8, 'range' => '8 - 10', 'text' => 'Con nói trôi chảy, nắm bắt được nội dung câu hỏi các từ để hỏi cơ bản level Starters từ cô giáo, từ vựng các chủ đề quen thuộc con nắm khá tốt, con có thể / chưa trả lời được cả câu; khá tự tin và trả lời tốt.'],
                ],
            ],
            'placements' => [
                ['lt' => 20, 'range' => '10 - 20', 'class' => 'PRE STARTERS _ FAM 1 (TỪ ĐẦU _ DƯỚI U5)'],
                ['lte' => 30, 'range' => '20 - 30', 'class' => 'STARTERS (FAM 1 _ UNIT 6 - 10)'],
                ['range' => '30 - 40', 'class' => 'STARTERS (FAM 1 _ UNIT 7 - 12)'],
            ],
        ],
        'khoi_3_4' => [
            'label' => 'Khối 3 lên 4',
            'level' => 'Movers',
            'max' => ['listening' => 15, 'reading_writing' => 20, 'speaking' => 10],
            'bands' => [
                'listening' => [
                    ['min' => 0, 'range' => '1 - 6', 'text' => 'Kĩ năng nghe của con ở mức độ hình thành cơ bản. Con nghe nhận diện một số key words đơn giản, chọn theo nhận diện key words, chưa nắm được nội dung của cả câu chưa phân biệt được các thông tin gây nhiễu và các thông tin đa chiều. Các dạng bài nghe YLE con chưa làm quen tốt.'],
                    ['min' => 6, 'range' => '6 - 10', 'text' => 'Con có kĩ năng nghe trung bình khá, con bắt đầu nghe và nhận diện được các thông tin đơn giản trong bài nghe, các câu ngắn, các thông tin 1 chiều. Con còn gặp khó khăn với kĩ năng xử lí bài nghe dạng nối tranh / viết thông tin / chọn tranh. Các thông tin đa chiều con chưa nhận diện được!'],
                    ['min' => 11, 'range' => '11 - 15', 'text' => 'Con nghe khá / tốt, nắm được hầu hết nội dung bài nghe level Movers. Con có thể phân biệt được các thông tin đơn chiều, các thông tin gây nhiễu. Con nghe và nắm bắt được thông tin để ghi chép được.'],
                ],
                'reading_writing' => [
                    ['min' => 0, 'range' => '1 - 7', 'text' => 'Con nhận diện các từ đơn cơ bản, chưa xây dựng kĩ năng viết cơ bản. Con nhận diện từ đơn, từ các keyword, chưa hiểu ý nghĩa của cả câu dài, chưa linh hoạt trong các dạng bài đọc điền từ, kĩ năng xử lí ngữ pháp trong các bài đọc, viết còn yếu, nền ngữ pháp chưa chắc chắn. Con hiểu câu hỏi / chưa hiểu câu hỏi nhưng chưa nhớ chính tả từ vựng. Cần hệ thống kiến thức và tăng nền từ vựng.'],
                    ['min' => 7, 'range' => '7 - 15', 'text' => 'Con có kiến thức cơ bản, con đọc hiểu được các câu ngắn và kết nối được thông tin. Con hiểu câu hỏi, viết được các cụm từ ngắn để trả lời. Tuy nhiên kĩ năng xử lí ngữ pháp tổng hợp nâng cao trong bài chọn chưa thực sự linh hoạt. Ngữ pháp cần được hệ thống và học nâng cao hơn. Kĩ năng sắp xếp câu khá / chưa tốt, Con bám theo cấu trúc cơ bản để viết câu.'],
                    ['min' => 15, 'range' => '15 - 20', 'text' => 'Con có nền từ vựng và cấu trúc khá, con có nền kĩ năng xử lí các dạng bài level Movers. Con đọc câu ngắn, hiểu nội dung, đọc hiểu câu hỏi và đưa ra được câu trả lời. Con nắm được cấu trúc câu cơ bản, xử lí bài tập sắp xếp khá / tốt. Con có hệ thống kiến thức ngữ pháp khá.'],
                ],
                'speaking' => [
                    ['min' => 0, 'range' => '2 - 4', 'text' => 'Con nghe hiểu cơ bản, nhận diện nội dung câu hỏi nhưng vẫn còn nhầm lẫn, nền từ yếu, chưa trả lời linh hoạt, thành thạo các dạng câu hỏi. Chưa quen với việc xây dựng câu và xử lí các bài tập kĩ năng tìm lỗi sai, mô tả tranh.'],
                    ['min' => 5, 'range' => '5 - 7', 'text' => 'Con có kĩ năng nghe nói cơ bản, nhận diện được các câu hỏi cơ bản, đưa ra được câu trả lời linh hoạt / còn chưa tự tin. Từ vựng đủ dùng để đưa ra được nội dung cho câu trả lời phù hợp. Con dựng câu còn chưa linh hoạt, còn sai cấu trúc, chưa nói được câu dài, các kĩ năng mô tả tranh, so sánh khác biệt cần được luyện tập thêm.'],
                    ['min' => 8, 'range' => '8 - 10', 'text' => 'Con nói trôi chảy, nắm bắt được nội dung câu hỏi các từ để hỏi level Movers từ cô giáo, từ vựng các chủ đề quen thuộc con nắm khá tốt, Con có kĩ năng nói được câu, nhận diện điểm khác biệt và mô tả tranh khá.'],
                ],
            ],
            'placements' => [
                ['lt' => 20, 'range' => '10 - 20', 'class' => 'FAM 2 (NỬA ĐẦU)'],
                ['lte' => 35, 'range' => '20 - 35', 'class' => 'FAM 2 (NỬA SAU)'],
                ['range' => '35 - 45', 'class' => 'Luyện MOVERS'],
            ],
        ],
        'khoi_4_5' => [
            'label' => 'Khối 4 lên 5',
            'level' => 'Movers',
            'max' => ['listening' => 15, 'reading_writing' => 15, 'speaking' => 10],
            'bands' => [
                'listening' => [
                    ['min' => 0, 'range' => '1 - 6', 'text' => 'Kĩ năng nghe của con ở mức độ hình thành cơ bản. Con nghe nhận diện một số key words đơn giản, chọn theo nhận diện key words, chưa nắm được nội dung của cả câu chưa phân biệt được các thông tin gây nhiễu và các thông tin đa chiều. Các dạng bài nghe YLE con chưa làm quen tốt.'],
                    ['min' => 6, 'range' => '6 - 10', 'text' => 'Con có kĩ năng nghe trung bình khá, con bắt đầu nghe và nhận diện được các thông tin đơn giản trong bài nghe, các câu ngắn, các thông tin 1 chiều. Con còn gặp khó khăn với kĩ năng xử lí bài nghe dạng nối tranh / viết thông tin / chọn tranh. Các thông tin đa chiều con chưa nhận diện được!'],
                    ['min' => 11, 'range' => '11 - 15', 'text' => 'Con nghe khá / tốt, nắm được hầu hết nội dung bài nghe level Movers. Con có thể phân biệt được các thông tin đơn chiều, các thông tin gây nhiễu. Con nghe và nắm bắt được thông tin để ghi chép được.'],
                ],
                'reading_writing' => [
                    ['min' => 0, 'range' => '1 - 5', 'text' => 'Con nhận diện các từ đơn cơ bản, chưa xây dựng kĩ năng viết cơ bản. Con nhận diện từ đơn, từ các keyword, chưa hiểu ý nghĩa của cả câu dài, chưa linh hoạt trong các dạng bài đọc điền từ, kĩ năng xử lí ngữ pháp trong các bài đọc, viết còn yếu, nền ngữ pháp chưa chắc chắn. Con hiểu câu hỏi / chưa hiểu câu hỏi nhưng chưa nhớ chính tả từ vựng. Cần hệ thống kiến thức và tăng nền từ vựng.'],
                    ['min' => 6, 'range' => '6 - 10', 'text' => 'Con có kiến thức cơ bản, con đọc hiểu được các câu ngắn và kết nối được thông tin. Con hiểu câu hỏi, viết được các cụm từ ngắn để trả lời. Tuy nhiên kĩ năng xử lí ngữ pháp tổng hợp nâng cao trong bài chọn chưa thực sự linh hoạt. Ngữ pháp cần được hệ thống và học nâng cao hơn. Kĩ năng sắp xếp câu khá / chưa tốt, Con bám theo cấu trúc cơ bản để viết câu.'],
                    ['min' => 11, 'range' => '11 - 15', 'text' => 'Con có nền từ vựng và cấu trúc khá, con có nền kĩ năng xử lí các dạng bài level Movers. Con đọc câu ngắn, hiểu nội dung, đọc hiểu câu hỏi và đưa ra được câu trả lời. Con nắm được cấu trúc câu cơ bản, xử lí bài tập sắp xếp khá / tốt. Con có hệ thống kiến thức ngữ pháp khá.'],
                ],
                'speaking' => [
                    ['min' => 0, 'range' => '2 - 4', 'text' => 'Con nghe hiểu cơ bản, nhận diện nội dung câu hỏi nhưng vẫn còn nhầm lẫn, nền từ yếu, chưa trả lời linh hoạt, thành thạo các dạng câu hỏi. Chưa quen với việc xây dựng câu và xử lí các bài tập kĩ năng tìm lỗi sai, mô tả tranh.'],
                    ['min' => 5, 'range' => '5 - 7', 'text' => 'Con có kĩ năng nghe nói cơ bản, nhận diện được các câu hỏi cơ bản, đưa ra được câu trả lời linh hoạt / còn chưa tự tin. Từ vựng đủ dùng để đưa ra được nội dung cho câu trả lời phù hợp. Con dựng câu còn chưa linh hoạt, còn sai cấu trúc, chưa nói được câu dài, các kĩ năng mô tả tranh, so sánh khác biệt cần được luyện tập thêm.'],
                    ['min' => 8, 'range' => '8 - 10', 'text' => 'Con nói trôi chảy, nắm bắt được nội dung câu hỏi các từ để hỏi level Movers từ cô giáo, từ vựng các chủ đề quen thuộc con nắm khá tốt, Con có kĩ năng nói được câu, nhận diện điểm khác biệt và mô tả tranh khá.'],
                ],
            ],
            'placements' => [
                ['lt' => 20, 'range' => '10 - 20', 'class' => 'FAM 2 (NỬA ĐẦU)'],
                ['lte' => 30, 'range' => '20 - 30', 'class' => 'FAM 2 (NỬA SAU)'],
                ['range' => '30 - 40', 'class' => 'Luyện MOVERS'],
            ],
        ],
    ];

    /** @return array<string, string> key => nhãn (4 khối có thang điểm + nhóm chọn lớp thủ công) */
    public static function gradeGroups(): array
    {
        $groups = array_map(fn (array $rubric) => $rubric['label'], self::RUBRICS);
        $groups[self::MANUAL_GROUP] = 'Học viên lớn / Mầm non (lớp 5–9, IELTS, người đi làm)';

        return $groups;
    }

    public static function isValidGroup(?string $group): bool
    {
        return $group !== null && array_key_exists($group, self::gradeGroups());
    }

    public static function hasRubric(?string $group): bool
    {
        return $group !== null && isset(self::RUBRICS[$group]);
    }

    public static function groupLabel(?string $group): string
    {
        return self::gradeGroups()[$group] ?? 'Chưa chọn khối lớp';
    }

    public static function noRubricNotice(): string
    {
        return self::NO_RUBRIC_NOTICE;
    }

    /** @return array{listening: int, reading_writing: int, speaking: int} */
    public static function maxScores(?string $group): array
    {
        return self::RUBRICS[$group]['max'] ?? self::MANUAL_MAX;
    }

    public static function maxTotal(?string $group): int
    {
        return array_sum(self::maxScores($group));
    }

    /**
     * Khối lớp mặc định theo mã đề (người chấm vẫn chọn lại được). Đề lớp 5 trở lên / IELTS / mầm non => nhóm thủ công.
     */
    public static function detectGradeGroup(?string $testCode): string
    {
        $code = strtoupper((string) $testCode);

        return match (true) {
            str_contains($code, 'PRE-G1'), str_contains($code, 'PRE_G1') => self::MANUAL_GROUP,
            str_contains($code, 'G1-G2'), str_contains($code, 'G1-G3') => 'khoi_1_2',
            str_contains($code, 'G2-G3') => 'khoi_2_3',
            str_contains($code, 'G3-G4') => 'khoi_3_4',
            str_contains($code, 'G4-G5'), str_contains($code, 'G4-G6') => 'khoi_4_5',
            default => self::MANUAL_GROUP,
        };
    }

    /** Lớp đề xuất theo tổng điểm (null nếu khối chưa có thang điểm). */
    public static function suggestClass(?string $group, float $total): ?string
    {
        foreach (self::RUBRICS[$group]['placements'] ?? [] as $placement) {
            if (self::placementMatches($placement, $total)) {
                return $placement['class'];
            }
        }

        return null;
    }

    /** Băng tổng điểm: 'lt' (< cận), 'lte' (≤ cận) hoặc không cận (băng cao nhất). */
    private static function placementMatches(array $placement, float $total): bool
    {
        return match (true) {
            isset($placement['lt']) => $total < $placement['lt'],
            isset($placement['lte']) => $total <= $placement['lte'],
            default => true,
        };
    }

    /** Nhận xét gợi ý của kỹ năng theo băng điểm (null nếu khối chưa có thang điểm). */
    public static function skillComment(?string $group, string $skill, ?float $score): ?string
    {
        if ($score === null) {
            return null;
        }
        $comment = null;
        foreach (self::RUBRICS[$group]['bands'][$skill] ?? [] as $band) {
            if ($score >= $band['min']) {
                $comment = $band['text'];
            }
        }

        return $comment;
    }

    /**
     * Chấm theo khối: tổng điểm, lớp đề xuất và nhận xét gợi ý từng kỹ năng.
     *
     * @return array{grade_group: string, has_rubric: bool, total: float, max_total: int, suggested_class: ?string,
     *     comments: array{listening: ?string, reading_writing: ?string, speaking: ?string}}
     */
    public static function evaluate(string $group, float $listening, float $readingWriting, float $speaking): array
    {
        $total = round($listening + $readingWriting + $speaking, 1);

        return [
            'grade_group' => $group,
            'has_rubric' => self::hasRubric($group),
            'total' => $total,
            'max_total' => self::maxTotal($group),
            'suggested_class' => self::suggestClass($group, $total),
            'comments' => [
                'listening' => self::skillComment($group, 'listening', $listening),
                'reading_writing' => self::skillComment($group, 'reading_writing', $readingWriting),
                'speaking' => self::skillComment($group, 'speaking', $speaking),
            ],
        ];
    }

    /**
     * Luật validate điểm theo khối (điểm tối đa từng kỹ năng lấy từ rubric). Khối chưa có thang điểm
     * bắt buộc chọn lớp thủ công.
     *
     * @return array<string, mixed>
     */
    public static function scoreRules(?string $group): array
    {
        $max = self::maxScores($group);

        return [
            'grade_group' => 'required|string|in:'.implode(',', array_keys(self::gradeGroups())),
            'listening_score' => 'required|numeric|min:0|max:'.$max['listening'],
            'reading_writing_score' => 'required|numeric|min:0|max:'.$max['reading_writing'],
            'speaking_score' => 'required|numeric|min:0|max:'.$max['speaking'],
            'chosen_class' => (self::hasRubric($group) ? 'nullable' : 'required').'|string|max:255',
            'listening_comment' => 'nullable|string|max:3000',
            'reading_writing_comment' => 'nullable|string|max:3000',
            'speaking_comment' => 'nullable|string|max:3000',
            'teacher_comments' => 'nullable|string|max:3000',
        ];
    }

    /** @return array<string, string> */
    public static function scoreMessages(?string $group): array
    {
        $max = self::maxScores($group);
        $label = self::groupLabel($group);

        return [
            'grade_group.required' => 'Vui lòng chọn khối lớp để chấm theo thang điểm.',
            'listening_score.max' => "Điểm Nghe tối đa {$max['listening']} ({$label}).",
            'reading_writing_score.max' => "Điểm Đọc & Viết tối đa {$max['reading_writing']} ({$label}).",
            'speaking_score.max' => "Điểm Nói tối đa {$max['speaking']} ({$label}).",
            'speaking_score.required' => 'Điểm Nói (Speaking) luôn do người chấm nhập tay.',
            'chosen_class.required' => self::NO_RUBRIC_NOTICE.': vui lòng nhập lớp xếp cho học viên.',
        ];
    }

    /** Ghép nhận xét từng kỹ năng + lớp xếp thành một đoạn (dùng cho bảng điểm / thông báo). */
    public static function composeComments(array $comments, ?string $chosenClass, ?string $note = null): string
    {
        $lines = [];
        foreach (self::SKILLS as $skill => $label) {
            if (filled($comments[$skill] ?? null)) {
                $lines[] = "【{$label}】: ".trim($comments[$skill]);
            }
        }
        if ($chosenClass) {
            $lines[] = "【Xếp lớp】: {$chosenClass}";
        }
        if (filled($note)) {
            $lines[] = trim($note);
        }

        return implode("\n\n", $lines);
    }

    /** Danh sách lớp đề xuất của mọi khối (gợi ý khi chọn lại lớp). */
    public static function classOptions(): array
    {
        return collect(self::RUBRICS)
            ->flatMap(fn (array $rubric) => array_column($rubric['placements'], 'class'))
            ->unique()->values()->all();
    }

    /** Toàn bộ bảng rubric để hiển thị (trang hướng dẫn / bảng tham chiếu). */
    public static function rubrics(): array
    {
        return self::RUBRICS;
    }

    /** Cấu hình gọn cho Alpine (tính tổng / lớp / nhận xét trực tiếp trên form). */
    public static function clientConfig(): array
    {
        $groups = [];
        foreach (self::gradeGroups() as $key => $label) {
            $rubric = self::RUBRICS[$key] ?? null;
            $groups[$key] = [
                'label' => $label,
                'has_rubric' => $rubric !== null,
                'max' => self::maxScores($key),
                'bands' => $rubric ? array_map(fn (array $bands) => array_map(fn (array $band) => ['min' => $band['min'], 'text' => $band['text']], $bands), $rubric['bands']) : [],
                'placements' => $rubric ? array_map(fn (array $p) => ['lt' => $p['lt'] ?? null, 'lte' => $p['lte'] ?? null, 'class' => $p['class']], $rubric['placements']) : [],
            ];
        }

        return ['groups' => $groups, 'notice' => self::NO_RUBRIC_NOTICE];
    }
}
