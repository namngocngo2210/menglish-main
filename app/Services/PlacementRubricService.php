<?php

namespace App\Services;

class PlacementRubricService
{
    /**
     * Generate detailed skill feedback and class recommendation based on MEnglish official rubric.
     */
    public static function evaluate(
        string $testCode,
        float $listeningScore,
        float $readingScore,
        float $writingScore,
        float $speakingScore,
        float $overallScore
    ): array {
        $gradeGroup = self::detectGradeGroup($testCode);

        $listeningFeedback = self::getListeningFeedback($gradeGroup, $listeningScore);
        $readingWritingFeedback = self::getReadingWritingFeedback($gradeGroup, $readingScore, $writingScore);
        $speakingFeedback = self::getSpeakingFeedback($gradeGroup, $speakingScore);
        $placement = self::getClassPlacement($gradeGroup, $overallScore, $listeningScore, $readingScore, $writingScore, $speakingScore);

        $combinedComments = "【Kỹ năng Nghe】: {$listeningFeedback}\n\n"
            . "【Kỹ năng Đọc & Viết】: {$readingWritingFeedback}\n\n"
            . "【Kỹ năng Nói】: {$speakingFeedback}\n\n"
            . "【Đánh giá Tổng quan & Xếp lớp】: Học viên đạt {$overallScore}/10 ({$placement['cefr_level']}). Đề xuất xếp vào lớp: {$placement['course']}.";

        return [
            'listening_feedback' => $listeningFeedback,
            'reading_writing_feedback' => $readingWritingFeedback,
            'speaking_feedback' => $speakingFeedback,
            'teacher_comments' => $combinedComments,
            'recommended_course' => $placement['course'],
            'cefr_level' => $placement['cefr_level'],
        ];
    }

    private static function detectGradeGroup(string $testCode): string
    {
        $code = strtoupper($testCode);
        if (str_contains($code, 'PRE-G1') || str_contains($code, 'PRE_G1') || str_contains($code, 'PRE-SCHOOL')) {
            return 'pre_g1';
        }
        if (str_contains($code, 'G1-G2') || str_contains($code, '1-2') || str_contains($code, 'G1-G3')) {
            return 'g1_g2';
        }
        if (str_contains($code, 'G2-G3') || str_contains($code, '2-3')) {
            return 'g2_g3';
        }
        if (str_contains($code, 'G3-G4') || str_contains($code, '3-4')) {
            return 'g3_g4';
        }
        if (str_contains($code, 'G4-G5') || str_contains($code, '4-5') || str_contains($code, 'G4-G6')) {
            return 'g4_g5';
        }
        if (str_contains($code, 'G5-G6') || str_contains($code, '5-6')) {
            return 'g5_g6';
        }
        if (str_contains($code, 'G6-G7') || str_contains($code, '6-7')) {
            return 'g6_g7';
        }
        if (str_contains($code, 'G7-G8') || str_contains($code, '7-8')) {
            return 'g7_g8';
        }
        if (str_contains($code, 'G8-G9') || str_contains($code, '8-9')) {
            return 'g8_g9';
        }
        return 'general';
    }

    private static function getListeningFeedback(string $group, float $score): string
    {
        return match ($group) {
            'pre_g1', 'g1_g2' => match (true) {
                $score >= 8.0 => "Con nghe tốt, nắm được hệ thống từ vựng của các chủ đề cơ bản xung quanh cuộc sống, nhận diện được từ vựng qua tranh và qua thông tin của bài hội thoại.",
                $score >= 5.0 => "Con đã có kĩ năng nghe cơ bản, con nghe và nhận diện được các từ vựng đơn giản thông qua tranh, bước đầu hình thành được kĩ năng nghe hội thoại, nhận diện một số từ key word, xác định được nội dung câu hỏi nhưng chưa theo được tốc độ của bài nghe còn bỏ lỡ thông tin.",
                default => "Con bắt đầu hình thành kĩ năng nghe cơ bản, con nhận diện được một số từ vựng đơn giản thông qua hình vẽ, các bài nghe nắm bắt thông tin để điền con chưa xử lí được, chưa nhận diện được các loại câu hỏi để tập trung tìm thông tin.",
            },
            'g2_g3' => match (true) {
                $score >= 7.5 => "Con nghe khá, quen với một số các dạng nghe cơ bản, nắm bắt được các thông tin trong bài nghe. Con phân biệt được các thông tin đa chiều, thông tin gây nhiễu, nhận diện được thông tin để điền. Cần chú ý hơn phần nối tranh / viết thông tin / nghe hội thoại chọn tranh.",
                $score >= 5.0 => "Con có kĩ năng nghe trung bình khá, con nghe và nhận diện được các thông tin trong các bài nghe nhận diện. Con phân biệt được các câu hỏi và nắm được thông tin để điền cơ bản. Con nghe thông tin đơn nhất, 1 chiều, còn bị nhầm lẫn với các thông tin đa chiều, thông tin gây nhiễu.",
                default => "Con có kĩ năng nghe cơ bản, con nghe và nhận diện được một số các tình huống bài nghe cơ bản, nhận diện được key words và chọn được một số câu trả lời đúng, chưa quen với các dạng bài nghe đa dạng.",
            },
            'g3_g4', 'g4_g5' => match (true) {
                $score >= 7.5 => "Con nghe khá / tốt, nắm được hầu hết nội dung bài nghe level Movers/Flyers. Con có thể phân biệt được các thông tin đơn chiều, các thông tin gây nhiễu. Con nghe và nắm bắt được thông tin để ghi chép chính xác.",
                $score >= 5.0 => "Con có kĩ năng nghe trung bình khá, con bắt đầu nghe và nhận diện được các thông tin đơn giản trong bài nghe, các câu ngắn, các thông tin 1 chiều. Con còn gặp khó khăn với kĩ năng xử lí bài nghe dạng nối tranh / viết thông tin / chọn tranh. Các thông tin đa chiều con chưa nhận diện được tốt.",
                default => "Kĩ năng nghe của con ở mức độ hình thành cơ bản. Con nghe nhận diện một số key words đơn giản, chọn theo nhận diện key words, chưa nắm được nội dung của cả câu, chưa phân biệt được các thông tin gây nhiễu và các thông tin đa chiều. Các dạng bài nghe YLE con chưa làm quen tốt.",
            },
            default => match (true) {
                $score >= 7.5 => "Năng lực nghe hiểu học thuật rất tốt, khả năng bắt keyword nhanh, phân biệt tốt bẫy âm và thông tin gây nhiễu trong đoạn hội thoại phức tạp.",
                $score >= 5.0 => "Kỹ năng nghe hiểu ở mức khá, nắm bắt được nội dung chính và các câu ngắn 1 chiều, cần rèn luyện thêm kỹ năng take note và xử lý thông tin đa chiều.",
                default => "Kỹ năng nghe ở mức căn bản, bắt đầu nhận diện được các từ khóa quen thuộc, cần luyện tập phản xạ nghe thường xuyên để theo kịp tốc độ bài thi.",
            },
        };
    }

    private static function getReadingWritingFeedback(string $group, float $readingScore, float $writingScore): string
    {
        $avg = ($readingScore + $writingScore) / 2;

        return match ($group) {
            'pre_g1', 'g1_g2' => match (true) {
                $avg >= 7.5 => "Con có vốn từ cơ bản về hệ thống các từ vựng chủ đề xung quanh, con nhớ chính tả từ, nắm được nội dung câu đơn giản. Kĩ năng xử lí bài tập Khá / Tốt.",
                $avg >= 5.0 => "Con nhận diện được cơ bản một số từ vựng, chưa nhớ chính tả từ nên còn nhầm lẫn từ vựng. Con đọc câu chưa hiểu hết nội dung nhưng nhận diện được một số câu để phân biệt đúng sai.",
                default => "Con chưa có nền từ tốt, nhận diện một số từ đơn cơ bản, chưa hình thành kĩ năng nhớ từ vựng, con đọc câu chưa phân biệt được đúng sai.",
            },
            'g2_g3' => match (true) {
                $avg >= 7.5 => "Con có nền từ khá tốt, con đọc hiểu những câu cơ bản, xử lí bài tập đúng sai linh hoạt. Con hình thành kĩ năng đọc hiểu cơ bản khá, thỉnh thoảng còn sai một vài từ. Kĩ năng xử lí bài tập kĩ năng đọc khá / tốt. Ngữ pháp con nắm cơ bản, xử lí bài sắp xếp câu linh hoạt.",
                $avg >= 5.0 => "Con nhận diện được cơ bản một số từ vựng. Con chưa hiểu hết ý nghĩa câu mà nhận diện đúng / sai dựa vào key word trong mỗi câu. Kĩ năng đọc điền từ con chưa xử lí linh hoạt. Ngữ pháp cần trau dồi để xử lí những bài sắp xếp câu.",
                default => "Con chưa có nền từ tốt, nhận diện một số từ đơn cơ bản, chưa hình thành kĩ năng nhớ từ vựng, con đọc câu chưa phân biệt được đúng sai, gặp khó khăn trong các kĩ năng đọc điền từ. Nền ngữ pháp yếu nên chưa sắp xếp được câu.",
            },
            'g3_g4', 'g4_g5' => match (true) {
                $avg >= 7.5 => "Con có nền từ vựng và cấu trúc khá, con có nền kĩ năng xử lí các dạng bài level Movers/Flyers. Con đọc câu ngắn, hiểu nội dung, đọc hiểu câu hỏi và đưa ra được câu trả lời. Con nắm được cấu trúc câu cơ bản, xử lí bài tập sắp xếp khá / tốt. Có hệ thống kiến thức ngữ pháp khá, thỉnh thoảng còn sai sót với những cấu trúc nâng cao.",
                $avg >= 5.0 => "Con có kiến thức cơ bản, con đọc hiểu được các câu ngắn và kết nối được thông tin. Con hiểu câu hỏi, viết được các cụm từ ngắn để trả lời. Tuy nhiên kĩ năng xử lí ngữ pháp tổng hợp nâng cao trong bài chọn chưa thực sự linh hoạt. Ngữ pháp cần được hệ thống và học nâng cao hơn.",
                default => "Con nhận diện các từ đơn cơ bản, chưa xây dựng kĩ năng viết cơ bản. Con nhận diện từ đơn từ các keyword, chưa hiểu ý nghĩa của cả câu dài, chưa linh hoạt trong các dạng bài đọc điền từ, kĩ năng xử lí ngữ pháp trong các bài đọc/viết còn yếu, nền ngữ pháp chưa chắc chắn.",
            },
            default => match (true) {
                $avg >= 7.5 => "Nền tảng từ vựng và ngữ pháp vững chắc, đọc hiểu văn bản nhanh, triển khai bài viết có cấu trúc rõ ràng và sử dụng linh hoạt các liên từ.",
                $avg >= 5.0 => "Đọc hiểu câu đơn và đoạn văn ngắn tốt, nắm được cấu trúc ngữ pháp thông dụng, cần trau dồi vốn từ học thuật và cải thiện kỹ năng liên kết câu trong Writing.",
                default => "Đang trong quá trình củng cố ngữ pháp căn bản, nhận diện được các cấu trúc đơn lẻ, cần tăng cường vốn từ vựng và rèn luyện kỹ năng viết câu hoàn chỉnh.",
            },
        };
    }

    private static function getSpeakingFeedback(string $group, float $score): string
    {
        return match ($group) {
            'pre_g1', 'g1_g2' => match (true) {
                $score >= 7.5 => "Con nói trôi chảy, nắm bắt được nội dung câu hỏi các từ để hỏi cơ bản level Starters từ cô giáo, từ vựng các chủ đề quen thuộc con nắm khá tốt, con có thể trả lời được cả câu; khá tự tin và trả lời tốt.",
                $score >= 5.0 => "Con đã có kĩ năng nghe nói cơ bản, có nhận diện được một số câu hỏi cơ bản theo tranh cô hỏi, con tự tin. Phát âm tương đối tốt, một số câu hỏi với từ để hỏi khó hơn con chưa nhận diện được hết.",
                default => "Con chưa hình thành kĩ năng nghe nói cơ bản, chưa nhận diện được các câu hỏi cơ bản, chỉ nắm bắt được 1-2 câu hỏi thông tin cá nhân đơn giản nhất.",
            },
            'g2_g3', 'g3_g4', 'g4_g5' => match (true) {
                $score >= 7.5 => "Con nói trôi chảy, nắm bắt được nội dung câu hỏi các từ để hỏi level Movers/Flyers từ cô giáo, từ vựng các chủ đề quen thuộc con nắm khá tốt. Con có kĩ năng nói được câu dài, nhận diện điểm khác biệt và mô tả tranh tốt.",
                $score >= 5.0 => "Con có kĩ năng nghe nói cơ bản, nhận diện được các câu hỏi cơ bản, đưa ra được câu trả lời phù hợp nhưng chưa thực sự tự tin. Con dựng câu còn chưa linh hoạt, còn sai cấu trúc, các kĩ năng mô tả tranh, so sánh khác biệt cần được luyện tập thêm.",
                default => "Con nghe hiểu cơ bản, nhận diện nội dung câu hỏi nhưng vẫn còn nhầm lẫn, nền từ yếu, chưa trả lời linh hoạt các dạng câu hỏi. Chưa quen với việc xây dựng câu và xử lí các bài tập kĩ năng tìm lỗi sai, mô tả tranh.",
            },
            default => match (true) {
                $score >= 7.5 => "Phản xạ giao tiếp tự nhiên, phát âm chuẩn và có ngữ điệu tốt, trình bày ý tưởng mạch lạc khi mô tả tranh và trả lời câu hỏi chuyên sâu.",
                $score >= 5.0 => "Giao tiếp được các câu hỏi thông dụng hàng ngày, phát âm tương đối rõ ràng, cần cải thiện sự trôi chảy và tự tin khi mở rộng câu trả lời.",
                default => "Phản xạ giao tiếp còn rụt rè, trả lời bằng từ đơn hoặc cụm từ ngắn, cần tạo môi trường tương tác 1-1 thường xuyên để bật phản xạ.",
            },
        };
    }

    private static function getClassPlacement(string $group, float $overall, float $listening, float $reading, float $writing, float $speaking): array
    {
        return match ($group) {
            'pre_g1' => match (true) {
                $overall >= 7.5 => ['cefr_level' => 'Pre-A1 (Starters)', 'course' => 'STARTERS (FAM 1 _ BÀI 1-6)'],
                $overall >= 5.0 => ['cefr_level' => 'Pre-Starters', 'course' => 'PRE STARTERS (FAM 0)'],
                default => ['cefr_level' => 'Pre-School Beginner', 'course' => 'Lớp Tiền Tiểu Học & Phản Xạ Nghe Nói'],
            },
            'g1_g2' => match (true) {
                $overall >= 7.0 => ['cefr_level' => 'A1 (Starters)', 'course' => 'STARTERS (FAM 1 _ TỪ BÀI 5 - 10)'],
                $overall >= 4.5 => ['cefr_level' => 'Pre-A1 (Starters Entry)', 'course' => 'STARTERS (FAM 1 _ Ở NHỮNG BÀI ĐẦU)'],
                default => ['cefr_level' => 'Pre-Starters Foundation', 'course' => 'PRE STARTERS (FAM 0)'],
            },
            'g2_g3' => match (true) {
                $overall >= 7.0 => ['cefr_level' => 'A1 (Starters Advanced)', 'course' => 'STARTERS (FAM 1 _ UNIT 7 - 12)'],
                $overall >= 5.0 => ['cefr_level' => 'A1 (Starters Mid)', 'course' => 'STARTERS (FAM 1 _ UNIT 6 - 10)'],
                default => ['cefr_level' => 'Pre-A1 Foundation', 'course' => 'PRE STARTERS _ FAM 1 (TỪ ĐẦU _ DƯỚI U5)'],
            },
            'g3_g4' => match (true) {
                $overall >= 7.0 => ['cefr_level' => 'A1+ (Movers Exam Prep)', 'course' => 'Luyện MOVERS'],
                $overall >= 5.0 => ['cefr_level' => 'A1 (Movers Mid)', 'course' => 'FAM 2 (NỬA SAU)'],
                default => ['cefr_level' => 'A1 (Movers Entry)', 'course' => 'FAM 2 (NỬA ĐẦU)'],
            },
            'g4_g5' => match (true) {
                $overall >= 7.0 => ['cefr_level' => 'A2 (Flyers Exam Prep)', 'course' => 'Luyện MOVERS / FLYERS'],
                $overall >= 5.0 => ['cefr_level' => 'A1+ (Movers Advanced)', 'course' => 'FAM 2 (NỬA SAU)'],
                default => ['cefr_level' => 'A1 (Movers Entry)', 'course' => 'FAM 2 (NỬA ĐẦU)'],
            },
            'g5_g6' => match (true) {
                $overall >= 7.5 => ['cefr_level' => 'A2 (Flyers / KET)', 'course' => 'Luyện FLYERS / KET Nâng Cao'],
                $overall >= 5.5 => ['cefr_level' => 'A2 (Elementary)', 'course' => 'Tiếng Anh THCS Cơ Bản Lớp 6'],
                default => ['cefr_level' => 'A1+ Foundation', 'course' => 'Lấy Gốc Tiếng Anh Lớp 6'],
            },
            'g6_g7', 'g7_g8' => match (true) {
                $overall >= 7.5 => ['cefr_level' => 'B1 (PET / IELTS Pre)', 'course' => 'IELTS Foundation 4.5 - 5.5'],
                $overall >= 5.5 => ['cefr_level' => 'A2+ (KET High)', 'course' => 'Tiếng Anh Nâng Cao Khối 7-8'],
                default => ['cefr_level' => 'A2 (KET Foundation)', 'course' => 'Bồi Dưỡng Ngữ Pháp & Kỹ Năng THCS'],
            },
            default => match (true) {
                $overall >= 7.0 => ['cefr_level' => 'C1 (Advanced)', 'course' => 'IELTS Master 7.5+'],
                $overall >= 6.0 => ['cefr_level' => 'B2 (Upper-Intermediate)', 'course' => 'IELTS Intensive 6.5'],
                $overall >= 5.0 => ['cefr_level' => 'B1 (Intermediate)', 'course' => 'IELTS Foundation 5.0'],
                $overall >= 3.5 => ['cefr_level' => 'A2 (Elementary)', 'course' => 'Giao tiếp Cơ bản Pro A2'],
                default => ['cefr_level' => 'A1 (Beginner)', 'course' => 'Tiếng Anh Mất Gốc (Starter A1)'],
            },
        };
    }
}
