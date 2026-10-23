<?php

namespace App\Services\Syllabus\StageImport;

/** Nội dung file syllabus 1 chặng theo form Sheet 1: ô đầu sheet + các dòng buổi (đã bỏ dòng trống / dòng ví dụ). */
final readonly class StageSheet
{
    /** @param  list<StageSheetRow>  $rows */
    public function __construct(
        public string $curriculumLabel,
        public string $stageName,
        public string $version,
        public array $rows,
        public int $skippedExamples,
    ) {}
}
