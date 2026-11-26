<?php

namespace Tests\Feature;

use App\Models\ClassModel;
use App\Models\Penalty;
use App\Models\Student;
use App\Models\TeacherTimesheet;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TestSeedTk0167Tk0170Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class TestSeedTk0167Tk0170SeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runSeederForReal(PermissionSeeder::class);
        $this->runSeederForReal(RoleSeeder::class);
    }

    private function counts(): array
    {
        return collect(['classes', 'class_sessions', 'students', 'users', 'teacher_timesheets', 'teacher_hourly_rates', 'penalties', 'homeworks', 'homework_submissions',
            'mini_test_scores', 'tuition_receipts', 'operating_expenses', 'student_tuitions', 'holidays', 'rooms', 'class_enrollments', 'crm_customers'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    public function test_seeds_expected_data_and_is_idempotent(): void
    {
        app(TestSeedTk0167Tk0170Seeder::class)->run();
        $first = $this->counts();

        // GD-05: NN-A 20 x 90 minutes valid, 1 absent; PT-C substitutes PT-B.
        $nnA = User::where('email', 'nn-a.gd@test.menglish')->firstOrFail();
        $this->assertSame(20, TeacherTimesheet::where('user_id', $nnA->id)->where('status', 'valid')->count());
        $this->assertSame(1, TeacherTimesheet::where('user_id', $nnA->id)->where('status', 'invalid')->count());
        $ptB = User::where('email', 'pt-b.gd@test.menglish')->firstOrFail();
        $ptC = User::where('email', 'pt-c.gd@test.menglish')->firstOrFail();
        $this->assertSame(1, TeacherTimesheet::where('user_id', $ptC->id)->where('type', 'sub')->count());
        $this->assertSame(0, TeacherTimesheet::where('user_id', $ptB->id)->count());
        $this->assertSame(0, Penalty::withoutViewerScope()->where('user_id', $nnA->id)->count());

        // Everything is on the Test GD branch and tagged.
        $this->assertSame(0, ClassModel::where('code', 'like', 'TESTSEED%')->where('branch_id', '!=', User::where('email', 'nn-a.gd@test.menglish')->value('branch_id'))->count());
        $this->assertSame(2, Penalty::withoutViewerScope()->where('notes', 'like', 'TESTSEED GV-A vi phạm%')->count());
        $this->assertSame(2, Student::where('name', 'TESTSEED Trung Ten')->count());

        app(TestSeedTk0167Tk0170Seeder::class)->run();
        $this->assertSame($first, $this->counts());
    }

    public function test_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        $before = $this->counts();

        try {
            app(TestSeedTk0167Tk0170Seeder::class)->run();
            $this->fail('Seeder must refuse to run in production.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('staging/local/testing', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame($before, $this->counts());
    }
}
