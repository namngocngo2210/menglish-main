<?php

namespace App\Support\Approvals;

/** Kết quả duyệt / từ chối một mục (thông điệp lấy từ module). */
final readonly class ApprovalResult
{
    public function __construct(
        public bool $ok,
        public string $message,
    ) {}

    public static function success(string $message): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
