<?php

namespace Tests\Feature;

use App\Services\Syllabus\Import\SyllabusImportTemplate;
use App\Services\Syllabus\Import\SyllabusPackageReader;
use App\Services\Syllabus\Import\SyllabusPackageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\BuildsSyllabusPackages;
use Tests\TestCase;
use ZipArchive;

class SyllabusImportTemplateTest extends TestCase
{
    use BuildsSyllabusPackages, RefreshDatabase;

    private function buildTemplate(): string
    {
        $path = (new SyllabusImportTemplate)->build();
        $this->assertFileExists($path);
        register_shutdown_function(fn () => @unlink($path));

        return $path;
    }

    public function test_template_zip_contains_workbook_and_sample_documents(): void
    {
        $path = $this->buildTemplate();
        $dir = $this->newPackageDir();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertNotFalse($zip->locateName('syllabus.xlsx'));
        foreach (['tai-lieu/chang-1/slide-buoi-1.pdf', 'tai-lieu/chang-1/hinh-minh-hoa.png', 'tai-lieu/chang-1/audio-nghe.mp3', 'tai-lieu/chang-2/video-mau.mp4'] as $sample) {
            $this->assertNotFalse($zip->locateName($sample), $sample);
        }
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
        $this->assertSame(4, $plan->summary()['documents']);
        $this->assertSame(['mp3', 'pdf', 'png'], collect($plan->stages[0]['documents'])->pluck('extension')->sort()->values()->all());
        $this->assertSame(['mp4'], collect($plan->stages[1]['documents'])->pluck('extension')->all());
    }
}
