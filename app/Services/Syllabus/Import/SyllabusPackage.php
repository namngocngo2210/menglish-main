<?php

namespace App\Services\Syllabus\Import;

/**
 * Nội dung thô đọc từ gói import. Mỗi *Rows là array<int $excelRow, array<string $key, ?string>>
 * (giá trị đã trim, ô trống = null).
 */
final readonly class SyllabusPackage
{
    /**
     * @param  list<PackageFile>  $files
     * @param  list<string>  $ignored  đường dẫn bị bỏ qua (file rác, ngoài tai-lieu/)
     * @param  list<string>  $missing  lỗi thiếu sheet / cột
     */
    public function __construct(
        public array $curriculumRows,
        public array $stageRows,
        public array $lessonRows,
        public array $files,
        public array $ignored,
        public array $missing,
        public bool $isZip,
    ) {}
}
