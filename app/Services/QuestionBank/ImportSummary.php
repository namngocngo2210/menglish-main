<?php

namespace App\Services\QuestionBank;

/** Kết quả một lần import ngân hàng câu hỏi. */
final class ImportSummary
{
    /**
     * @param  list<string>  $errors  lỗi theo file (file hỏng, thiếu ảnh…)
     * @param  array<string, int>  $skipReasons  lý do bỏ qua câu => số câu
     */
    public function __construct(
        public readonly int $created,
        public readonly int $updated,
        public readonly int $kept,
        public readonly int $skipped,
        public readonly array $errors,
        public readonly array $skipReasons = [],
    ) {}

    public function message(): string
    {
        $message = "Thêm {$this->created}, cập nhật {$this->updated}, giữ nguyên {$this->kept}, bỏ qua {$this->skipped}.";

        if ($this->errors !== []) {
            $message .= ' Lỗi: '.count($this->errors).'.';
        }

        return $message;
    }
}
