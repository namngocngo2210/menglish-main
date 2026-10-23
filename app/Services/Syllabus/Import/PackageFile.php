<?php

namespace App\Services\Syllabus\Import;

/** Một tài liệu trong gói zip đã giải nén. */
final readonly class PackageFile
{
    /**
     * @param  string  $path  đường dẫn trong zip, đã bỏ thư mục bọc (vd 'tai-lieu/chang-1/Slide.pdf')
     * @param  string  $localPath  đường dẫn file đã giải nén trên đĩa
     * @param  bool  $renamed  tên gốc không phải UTF-8 nên đã được chuyển mã
     */
    public function __construct(
        public string $path,
        public string $localPath,
        public int $size,
        public bool $renamed = false,
    ) {}
}
