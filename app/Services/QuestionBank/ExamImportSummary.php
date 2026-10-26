<?php

namespace App\Services\QuestionBank;

/** Kết quả một lần import đề cố định: từng dòng đã ghi / giữ nguyên / lỗi / cảnh báo để hiện cho Học thuật. */
final class ExamImportSummary
{
    /** @var list<string> */
    public array $saved = [];

    /** @var list<string> */
    public array $kept = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<int> */
    public array $examIds = [];

    public function message(): string
    {
        $message = 'Đã nhập '.count($this->saved).' đề';
        if ($this->kept !== []) {
            $message .= ', giữ nguyên '.count($this->kept).' đề đã mở';
        }
        if ($this->errors !== []) {
            $message .= ', lỗi '.count($this->errors).' sheet';
        }

        return $message.'.';
    }
}
