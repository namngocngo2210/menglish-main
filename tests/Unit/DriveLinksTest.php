<?php

namespace Tests\Unit;

use App\Services\GoogleDrive\DriveLinks;
use PHPUnit\Framework\TestCase;

/** Nhận dạng ID file / thư mục Google Drive từ link trong syllabus (docs/kho-syllabus.md §7.4). */
class DriveLinksTest extends TestCase
{
    public function test_parses_drive_file_and_folder_links(): void
    {
        $cases = [
            'https://docs.google.com/presentation/d/1RthTsabf4GJ-DzW05qrPya5SH1ecnJOM/edit?usp=drive_link' => ['1RthTsabf4GJ-DzW05qrPya5SH1ecnJOM', 'file'],
            'https://docs.google.com/document/d/1h48EfIg_VZ5yjHS6gUansW5ckqFtcgO2/edit' => ['1h48EfIg_VZ5yjHS6gUansW5ckqFtcgO2', 'file'],
            'https://docs.google.com/spreadsheets/d/abcDEF123_-x/edit#gid=0' => ['abcDEF123_-x', 'file'],
            'https://drive.google.com/file/d/1Dm5hNlYBjLNcwi7SlHTlaVnfveN3a5Mw/view?usp=drive_link' => ['1Dm5hNlYBjLNcwi7SlHTlaVnfveN3a5Mw', 'file'],
            'https://drive.google.com/drive/folders/1BO76vxZQCOTF_BaOHoro39odVl9T2ITK?usp=sharing' => ['1BO76vxZQCOTF_BaOHoro39odVl9T2ITK', 'folder'],
            'https://drive.google.com/drive/u/1/folders/1W-yBCEXzHfEXy80Uxl7tK8EvO91uqRfu' => ['1W-yBCEXzHfEXy80Uxl7tK8EvO91uqRfu', 'folder'],
            'https://drive.google.com/open?id=1k3qSi1dwY__4UAmjphDm1rnzTkS_Hy4J' => ['1k3qSi1dwY__4UAmjphDm1rnzTkS_Hy4J', 'file'],
        ];
        foreach ($cases as $url => [$id, $kind]) {
            $this->assertSame(['id' => $id, 'kind' => $kind], DriveLinks::parse($url), $url);
        }
    }

    public function test_non_drive_links_are_ignored(): void
    {
        foreach ([null, '', 'https://quizizz.com/admin/quiz/62e3930c0bb927001ed0093e', 'https://wayground.com/admin/assessment/6909aa9a', 'https://example.com/d/abc', 'not a url'] as $url) {
            $this->assertNull(DriveLinks::parse($url), (string) $url);
        }
    }
}
