<?php

namespace App\Support\Approvals;

use Carbon\CarbonInterface;

/** Một dòng trong hộp "Việc cần duyệt" (chỉ để hiển thị). `meta` = nhãn => giá trị, hiện trong modal chi tiết. */
final readonly class ApprovalItem
{
    /**
     * @param  string  $url  màn chi tiết / duyệt gốc của module
     * @param  string|null  $modalUrl  route chi tiết sẵn có mở được trong modal (htmx), nếu có
     * @param  array<string, string>  $meta
     */
    public function __construct(
        public string $source,
        public int $id,
        public string $title,
        public ?string $subtitle,
        public string $url,
        public ?CarbonInterface $createdAt = null,
        public ?float $amount = null,
        public ?string $modalUrl = null,
        public array $meta = [],
        public ?string $flag = null,
    ) {}

    /** Giá trị checkbox / tham số bulk. */
    public function ref(): string
    {
        return $this->source.':'.$this->id;
    }
}
