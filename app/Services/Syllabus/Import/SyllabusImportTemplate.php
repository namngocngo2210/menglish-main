<?php

namespace App\Services\Syllabus\Import;

use App\Exports\SyllabusImportTemplateExport;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use ZipArchive;

/** Dựng file zip mẫu import syllabus: syllabus.xlsx + tài liệu ví dụ trong tai-lieu/chang-1, chang-2. */
class SyllabusImportTemplate
{
    /** Tài liệu ví dụ (đường dẫn trong zip => file trong resources/syllabus-import-samples) để import thử được ngay. */
    public const SAMPLES = [
        'tai-lieu/chang-1/slide-buoi-1.pdf' => 'slide-buoi-1.pdf',
        'tai-lieu/chang-1/hinh-minh-hoa.png' => 'hinh-minh-hoa.png',
        'tai-lieu/chang-1/audio-nghe.mp3' => 'audio-nghe.mp3',
        'tai-lieu/chang-2/video-mau.mp4' => 'video-mau.mp4',
    ];

    /** @return string đường dẫn tuyệt đối tới file zip tạm; người gọi tự xoá sau khi gửi */
    public function build(): string
    {
        $dir = storage_path('app/private/syllabus-imports');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Không tạo được thư mục tạm.');
        }

        $path = $dir.'/mau-'.bin2hex(random_bytes(8)).'.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Không tạo được file zip mẫu.');
        }

        $zip->addFromString('syllabus.xlsx', Excel::raw(new SyllabusImportTemplateExport, ExcelFormat::XLSX));
        foreach (self::SAMPLES as $entry => $file) {
            $zip->addFile(resource_path('syllabus-import-samples/'.$file), $entry);
        }
        $zip->close();

        return $path;
    }
}
