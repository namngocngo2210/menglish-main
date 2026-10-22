<?php

namespace Tests\Feature;

use App\Services\Syllabus\Import\SyllabusImportTemplate;
use App\Services\Syllabus\Import\SyllabusPackageReader;
use App\Services\Syllabus\Import\SyllabusPackageValidator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\BuildsSyllabusPackages;
use Tests\TestCase;
use ZipArchive;

class SyllabusImportTemplateTest extends TestCase
{
    use BuildsSyllabusPackages;

    private function buildTemplate(): string
    {
        $path = (new SyllabusImportTemplate)->build();
        $this->assertFileExists($path);
        register_shutdown_function(fn () => @unlink($path));

        return $path;
    }

    public function test_template_zip_contains_workbook_and_stage_folders(): void
    {
        $path = $this->buildTemplate();
        $dir = $this->newPackageDir();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertNotFalse($zip->locateName('syllabus.xlsx'));
        $this->assertNotFalse($zip->locateName('tai-lieu/chang-1/'));
        $this->assertNotFalse($zip->locateName('tai-lieu/chang-2/'));
        $zip->extractTo($dir, 'syllabus.xlsx');
        $zip->close();

        $names = IOFactory::load($dir.'/syllabus.xlsx')->getSheetNames();
        $this->assertSame(['Hướng dẫn', 'Giáo trình', 'Chặng', 'Buổi'], $names);
    }

    public function test_template_passes_validation(): void
    {
        $package = (new SyllabusPackageReader)->read($this->buildTemplate(), $this->newPackageDir());
        $plan = (new SyllabusPackageValidator)->validate($package, true);

        $this->assertTrue($plan->ok());
        $this->assertSame(2, $plan->summary()['stages']);
        $this->assertSame(2, $plan->summary()['units']);
        $this->assertSame(4, $plan->summary()['lessons']);
    }
}
