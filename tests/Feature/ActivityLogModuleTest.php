<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_captures_operations_across_different_modules(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create([
            'name' => 'Admin Tuyệt Đối',
            'email' => 'admin.test@menglish.edu.vn',
        ]);
        $user->assignRole('admin');

        // 1. Perform CRM Operation (POST request)
        $this->actingAs($user)->post('/crm/customers', [
            'name' => 'Học viên Lead Test',
            'phone' => '0988 777 666',
            'email' => 'lead.test@gmail.com',
            'source' => 'Facebook Ads',
            'notes' => 'Cần tư vấn khóa IELTS',
        ]);

        // Verify activity log is created with module CRM & Leads
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'CRM & Leads',
            'causer_id' => $user->id,
        ]);

        // 2. Perform Placement Test Operation
        $this->actingAs($user)->post('/placement-tests', [
            'code' => 'TEST-AUDIT-LOG',
            'title' => 'Đề Test Audit Module',
            'target_level' => 'B1',
            'duration_minutes' => 45,
            'questions_count' => 10,
        ]);

        // Verify activity log is created with module Khảo sát & Đề thi
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'Khảo sát & Đề thi',
            'causer_id' => $user->id,
        ]);

        // 3. Perform Task creation
        $this->actingAs($user)->post('/tasks', [
            'title' => 'Chuẩn bị phòng học và giáo trình Unit 1',
            'assigned_to' => $user->id,
            'due_date' => now()->addDays(2)->format('Y-m-d'),
            'priority' => 'high',
            'type' => 'ta_support',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'Quản lý công việc',
            'causer_id' => $user->id,
        ]);

        // 4. Perform Support Ticket creation
        $this->actingAs($user)->post('/tickets', [
            'subject' => 'Hỗ trợ kiểm tra đường truyền phòng Lab',
            'category' => 'technical',
            'priority' => 'high',
            'description' => 'Mạng internet phòng Lab 01 đang bị chập chờn.',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'Ticket hỗ trợ',
            'causer_id' => $user->id,
        ]);

        // 5. View Activity Log index page with filters
        $response = $this->actingAs($user)->get(route('activity-logs.index'));
        $response->assertOk();
        $response->assertSee('Nhật Ký Vận Hành Toàn Hệ Thống');
        $response->assertSee('CRM & Leads');
        $response->assertSee('Khảo sát & Đề thi');
        $response->assertSee('Quản lý công việc');
        $response->assertSee('Ticket hỗ trợ');
    }
}
