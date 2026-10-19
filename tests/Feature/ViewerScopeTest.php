<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\DataScopeGuard;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Lọc dữ liệu tự động theo người xem (ScopedToViewer + DataScopeGuard): bật trong request web, tắt ở console / job /
 * đoạn bỏ lọc có chủ đích; bản ghi ngoài phạm vi mở theo id → 404; sinh mã chứng từ vẫn dò trên toàn bộ dữ liệu.
 */
class ViewerScopeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    private User $managerA;

    private SupportTicket $ticketA;

    private SupportTicket $ticketB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branchA = Branch::create(['name' => 'CN A', 'code' => 'VS-A', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'CN B', 'code' => 'VS-B', 'is_active' => true]);
        $this->managerA = $this->makeUser(Roles::MANAGER, $this->branchA);
        $this->ticketA = $this->ticket($this->makeUser(Roles::TEACHER_FULLTIME, $this->branchA), 'TK-2026-0001');
        $this->ticketB = $this->ticket($this->makeUser(Roles::TEACHER_FULLTIME, $this->branchB), 'TK-2026-0002');
    }

    public function test_queries_are_filtered_only_while_guard_is_enforcing(): void
    {
        $this->actingAs($this->managerA);
        $this->assertSame(2, SupportTicket::count(), 'Ngoài request web (console, test) không lọc.');

        $guard = DataScopeGuard::current();
        $guard->enforce();
        try {
            $this->assertSame([$this->ticketA->id], SupportTicket::pluck('id')->all());
            $this->assertNull(SupportTicket::find($this->ticketB->id));
            $this->assertSame(2, SupportTicket::withoutViewerScope()->count());
            $this->assertSame(2, DataScopeGuard::bypass(fn () => SupportTicket::count()));
            $this->assertSame(1, SupportTicket::count(), 'Hết đoạn bỏ lọc thì lọc lại.');

            // Lọc theo một người cụ thể thay cho người đang đăng nhập.
            $admin = $this->makeUser(Roles::ADMIN, $this->branchA);
            $this->assertSame(2, SupportTicket::visibleTo($admin)->count());

            // Job hàng đợi chạy đồng bộ ngay trong request vẫn thấy toàn bộ dữ liệu.
            dispatch(fn () => Cache::put('viewer-scope-job-count', SupportTicket::count()));
            $this->assertSame(2, Cache::get('viewer-scope-job-count'));
            $this->assertSame(1, SupportTicket::count());

            auth()->logout();
            $this->assertSame(0, SupportTicket::count(), 'Request chưa đăng nhập không thấy bản ghi nào.');
        } finally {
            $guard->enforce(false);
        }
    }

    public function test_guard_is_switched_off_after_each_request(): void
    {
        $this->actingAs($this->managerA)->get(route('tickets.index'))->assertOk();

        $this->assertFalse(DataScopeGuard::current()->active());
        $this->assertSame(2, SupportTicket::count());
    }

    public function test_admin_is_never_filtered(): void
    {
        $admin = $this->makeUser(Roles::ADMIN, $this->branchA);
        $this->actingAs($admin)->get(route('tickets.show', $this->ticketB->id))->assertOk();
        $this->actingAs($this->managerA)->get(route('tickets.show', $this->ticketB->id))->assertNotFound();
    }

    public function test_document_codes_are_unique_across_records_the_creator_cannot_see(): void
    {
        $teacher = $this->makeUser(Roles::TEACHER_FULLTIME, $this->branchA);

        $this->actingAs($teacher)->post(route('tickets.store'), [
            'title' => 'Máy chiếu hỏng', 'category' => 'facilities', 'priority' => 'medium', 'description' => 'Phòng 2',
        ])->assertSessionHasNoErrors();

        $codes = SupportTicket::pluck('code');
        $this->assertCount(3, $codes);
        $this->assertCount(3, $codes->unique(), 'Mã ticket mới không được trùng mã ticket người tạo không thấy.');
    }

    private function makeUser(string $role, Branch $branch): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function ticket(User $creator, string $code): SupportTicket
    {
        return SupportTicket::create([
            'code' => $code, 'title' => "Ticket {$code}", 'category' => 'technical_issue', 'priority' => 'medium',
            'description' => 'Mô tả', 'creator_id' => $creator->id, 'status' => 'open',
        ]);
    }
}
