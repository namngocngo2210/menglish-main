<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SepayConfiguration;
use App\Models\SepayTransaction;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_media_download_cannot_read_files_outside_managed_roots(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('media.download', ['id' => base64_encode(base_path('.env'))]));

        $response->assertNotFound();
    }

    public function test_media_rejects_executable_uploads_and_directory_traversal(): void
    {
        $upload = $this->actingAs($this->admin)->post(route('media.upload'), [
            'file' => UploadedFile::fake()->create('shell.php', 1, 'application/x-httpd-php'),
        ]);
        $upload->assertSessionHasErrors('file');

        $sentinel = base_path('security-sentinel.txt');
        File::put($sentinel, 'keep');
        try {
            $delete = $this->actingAs($this->admin)->delete(route('media.delete-folder'), [
                'folder_path' => '../../',
            ]);
            $delete->assertSessionHas('error');
            $this->assertFileExists($sentinel);
        } finally {
            File::delete($sentinel);
        }
    }

    public function test_sepay_requires_signature_and_ignores_outgoing_transactions(): void
    {
        // Webhook mặc định tắt qua env (SEPAY_WEBHOOK_ENABLED=false) — bật cho test này
        config(['services.sepay.webhook_enabled' => true]);

        $secret = 'test-only-sepay-secret';
        SepayConfiguration::create([
            'webhook_name' => 'Test webhook',
            'webhook_url' => 'https://example.test/webhook',
            'transaction_type' => 'all',
            'data_format' => 'json',
            'auth_method' => 'hmac_sha256',
            'secret_key' => $secret,
            'is_active' => true,
            'auto_retry' => false,
        ]);

        $payload = [
            'id' => 'SEPAY-OUT-001',
            'transferType' => 'out',
            'transferAmount' => 500000,
            'content' => 'HV-00001',
        ];

        $this->postJson(route('sepay.webhook.api'), $payload)->assertUnauthorized();

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $json, $secret);
        $response = $this->call('POST', route('sepay.webhook.api'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SEPAY_SIGNATURE' => $signature,
        ], $json);

        $response->assertOk();
        $this->assertDatabaseHas('sepay_transactions', [
            'sepay_id' => 'SEPAY-OUT-001',
            'status' => 'ignored',
        ]);
        $this->assertDatabaseCount('tuition_receipts', 0);

        // Delivery retries remain idempotent.
        $this->call('POST', route('sepay.webhook.api'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SEPAY_SIGNATURE' => $signature,
        ], $json)->assertOk();
        $this->assertSame(1, SepayTransaction::where('sepay_id', 'SEPAY-OUT-001')->count());
    }

    public function test_student_account_cannot_access_or_update_another_student(): void
    {
        $branch = Branch::create(['name' => 'Cầu Giấy', 'code' => 'CG']);
        $owner = User::factory()->create();
        $owner->assignRole('student');
        $other = User::factory()->create();
        $other->assignRole('student');

        Student::create([
            'user_id' => $owner->id,
            'name' => 'Owned student',
            'code' => 'HV-OWN',
            'phone' => '0900000001',
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
        $otherStudent = Student::create([
            'user_id' => $other->id,
            'name' => 'Other student',
            'code' => 'HV-OTHER',
            'phone' => '0900000002',
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->get(route('portal.student.home', ['studentId' => $otherStudent->id]))
            ->assertForbidden();

        $this->actingAs($owner)->post(route('portal.student.profile.update', $otherStudent->id), [
            'student_id' => $otherStudent->id,
            'phone' => '0900000000',
        ])->assertForbidden();
    }

    public function test_user_without_payroll_permission_is_forbidden(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');

        $this->actingAs($teacher)->get(route('payroll.periods.index'))->assertForbidden();
        $this->actingAs($teacher)->post(route('payroll.periods.store'), [
            'month' => 9,
            'year' => 2026,
        ])->assertForbidden();
    }
}
