<?php

namespace Tests\Feature;

use App\Http\Controllers\AcademicSystemController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AcademicSystemScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function getAdminUser(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $user = User::factory()->create([
            'name' => 'Test Admin',
            'email' => 'admin.test@menglish.local',
        ]);
        $user->assignRole('admin');

        return $user;
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $response = $this->get('/academic-system');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_access_academic_system_gallery(): void
    {
        $user = $this->getAdminUser();

        $response = $this->actingAs($user)->get('/academic-system');
        $response->assertStatus(200);
        $response->assertSee('MENGLISH System Screens');
        $response->assertSee('58 Giao diện Mới');
        $response->assertSee('01_Web_Admin');
        $response->assertSee('02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu');
        $response->assertSee('03_Cong_Giao_Vien');
        $response->assertSee('04_Cong_Phu_Huynh_Hoc_Sinh');
    }

    public function test_user_can_view_screens_from_all_four_categories_with_original_ui(): void
    {
        $user = $this->getAdminUser();

        // 1. Web Admin
        $res1 = $this->actingAs($user)->get('/academic-system/01_Web_Admin/01_quan_ly_tai_lieu_giao_trinh');
        $res1->assertStatus(200);
        $res1->assertSee('Quản lý tài liệu giáo trình');
        $res1->assertSee('menglish-admin-quick-bar');

        // 2. Quản lý Học thuật & Học vụ
        $res2 = $this->actingAs($user)->get('/academic-system/02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/01_cau_hinh_kpi_hoc_vu_1');
        $res2->assertStatus(200);
        $res2->assertSee('Cấu hình KPI');
        $res2->assertSee('menglish-admin-quick-bar');

        // 3. Cổng Giáo viên
        $res3 = $this->actingAs($user)->get('/academic-system/03_Cong_Giao_Vien/01_app_shell_cong_giao_vien');
        $res3->assertStatus(200);
        $res3->assertSee('menglish-admin-quick-bar');

        // 4. Cổng Phụ huynh / Học sinh
        $res4 = $this->actingAs($user)->get('/academic-system/04_Cong_Phu_Huynh_Hoc_Sinh/03_hoc_tap_cua_toi_nop_bai_tap');
        $res4->assertStatus(200);
        $res4->assertSee('menglish-admin-quick-bar');
    }

    public function test_shortcut_routes_redirect_correctly(): void
    {
        $user = $this->getAdminUser();

        $this->actingAs($user)->get('/academic-admin')
            ->assertRedirect(route('academic-system.index', ['cat' => '01_Web_Admin']));

        $this->actingAs($user)->get('/academic-ops')
            ->assertRedirect(route('academic-system.index', ['cat' => '02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu']));

        $this->actingAs($user)->get('/teacher-portal')
            ->assertRedirect(route('academic-system.index', ['cat' => '03_Cong_Giao_Vien']));

        $this->actingAs($user)->get('/parent-portal')
            ->assertRedirect(route('academic-system.index', ['cat' => '04_Cong_Phu_Huynh_Hoc_Sinh']));
    }

    public function test_mockup_hub_contains_all_academic_modules(): void
    {
        $user = $this->getAdminUser();

        $response = $this->actingAs($user)->get('/mockup-hub');
        $response->assertStatus(200);
        $response->assertSee('Quản trị Học thuật (Web Admin) — 58 Màn');
        $response->assertSee('Cổng Giáo Viên (Teacher Portal)');
        $response->assertSee('Cổng Phụ Huynh & Học Sinh');
        $response->assertSee('Gallery 58 Màn Mới');
    }

    public function test_all_58_screens_exist_and_render_200(): void
    {
        $user = $this->getAdminUser();
        $screens = AcademicSystemController::getScreens();

        $this->assertCount(58, $screens);

        foreach ($screens as $s) {
            $response = $this->actingAs($user)->get('/academic-system/' . $s['category_id'] . '/' . $s['folder_name']);
            $this->assertEquals(200, $response->getStatusCode(), "Screen {$s['category_id']}/{$s['folder_name']} should return 200 OK");
        }
    }

    public function test_screen_injects_real_data_and_client_engine(): void
    {
        $user = $this->getAdminUser();

        \App\Models\AcademicRecord::create([
            'screen_key' => '01_quan_ly_tai_lieu_giao_trinh',
            'module' => 'web_admin',
            'record_code' => 'TEST-001',
            'title' => 'Giáo trình IELTS Real Test 2026',
            'status' => 'active',
            'data' => [
                'type' => 'PDF',
                'level' => 'IELTS 7.5+',
                'creator' => 'Academic Admin',
            ],
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->get('/academic-system/01_Web_Admin/01_quan_ly_tai_lieu_giao_trinh');
        $response->assertStatus(200);
        $response->assertSee('__MENGLISH_REAL_DATA__');
        $response->assertSee('menglish-real-data-engine.js');
        $response->assertSee('Giáo trình IELTS Real Test 2026');
    }

    public function test_api_can_crud_academic_records(): void
    {
        $user = $this->getAdminUser();

        // 1. Create (POST)
        $storeResponse = $this->actingAs($user)->postJson('/api/academic-system/records', [
            'screen_key' => '01_quan_ly_tai_lieu_giao_trinh',
            'title' => 'Tài liệu Ngữ pháp Nâng cao MEnglish',
            'status' => 'active',
            'data' => [
                'code' => 'TL-999',
                'author' => 'Thầy Minh',
                'pages' => 120,
            ],
        ]);

        $storeResponse->assertStatus(201);
        $storeResponse->assertJsonFragment([
            'success' => true,
            'title' => 'Tài liệu Ngữ pháp Nâng cao MEnglish',
        ]);

        $recordId = $storeResponse->json('data.id');
        $this->assertDatabaseHas('academic_records', [
            'id' => $recordId,
            'title' => 'Tài liệu Ngữ pháp Nâng cao MEnglish',
            'record_code' => 'TL-999',
        ]);

        // 2. Read (GET)
        $getResponse = $this->actingAs($user)->getJson('/api/academic-system/records?screen_key=01_quan_ly_tai_lieu_giao_trinh');
        $getResponse->assertStatus(200);
        $getResponse->assertJsonFragment(['title' => 'Tài liệu Ngữ pháp Nâng cao MEnglish']);

        // 3. Update (PUT)
        $updateResponse = $this->actingAs($user)->putJson('/api/academic-system/records/' . $recordId, [
            'title' => 'Tài liệu Ngữ pháp Nâng cao MEnglish v2',
            'status' => 'in_review',
        ]);
        $updateResponse->assertStatus(200);
        $this->assertDatabaseHas('academic_records', [
            'id' => $recordId,
            'title' => 'Tài liệu Ngữ pháp Nâng cao MEnglish v2',
            'status' => 'in_review',
        ]);

        // 4. Action: Approve (POST)
        $actionResponse = $this->actingAs($user)->postJson('/api/academic-system/records/' . $recordId . '/action', [
            'action' => 'approve',
        ]);
        $actionResponse->assertStatus(200);
        $this->assertDatabaseHas('academic_records', [
            'id' => $recordId,
            'status' => 'approved',
        ]);

        // 5. Delete (DELETE)
        $deleteResponse = $this->actingAs($user)->deleteJson('/api/academic-system/records/' . $recordId);
        $deleteResponse->assertStatus(200);
        $this->assertSoftDeleted('academic_records', [
            'id' => $recordId,
        ]);
    }

    public function test_seeder_populates_5_records_for_every_screen_marked_as_seed(): void
    {
        $this->seed(\Database\Seeders\AcademicSystemSeeder::class);

        $screens = AcademicSystemController::getScreens();
        $this->assertCount(58, $screens);

        // Assert 58 screens * 5 = 290 seed records
        $totalSeedCount = \App\Models\AcademicRecord::where('is_seed', true)->count();
        $this->assertEquals(290, $totalSeedCount, "Total seed records must be 290 (5 records x 58 screens)");

        foreach ($screens as $s) {
            $screenKey = $s['category_id'] . '/' . $s['folder_name'];
            $screenRecords = \App\Models\AcademicRecord::where('screen_key', $screenKey)
                ->where('is_seed', true)
                ->get();

            $this->assertCount(5, $screenRecords, "Screen {$screenKey} must have exactly 5 seed records");

            foreach ($screenRecords as $record) {
                $this->assertTrue($record->is_seed);
                $this->assertStringStartsWith('[SEED]', $record->title);
                $this->assertStringStartsWith('SEED-', $record->record_code);
                $this->assertArrayHasKey('is_seed', $record->data);
                $this->assertTrue($record->data['is_seed']);
            }
        }
    }
}

