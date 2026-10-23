<?php

namespace App\Services\Syllabus\StageImport;

/** Một dòng buổi học trong file Sheet 1 — giá trị ô thô (đã trim, ô trống = null). */
final readonly class StageSheetRow
{
    public function __construct(
        public int $line,
        public ?string $type,
        public ?string $unitCell,
        public ?string $unitObjectives,
        public ?string $content,
        public ?string $procedure,
        public ?string $activity1,
        public ?string $activity2,
        public ?string $homeworkPaper,
        public ?string $homeworkQuizizz,
        public ?string $homeworkVideo,
        public ?string $pptUrl,
        public ?string $quizizzUrl,
        public ?string $outputProduct,
        public ?string $milestone,
        public ?string $note,
        public ?string $pptForeignUrl = null,
        public ?string $testUrl = null,
    ) {}
}
