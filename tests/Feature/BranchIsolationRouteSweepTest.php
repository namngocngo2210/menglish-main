<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassModel;
use App\Models\Course;
use App\Models\CrmCustomer;
use App\Models\Penalty;
use App\Models\StaffReport;
use App\Models\Student;
use App\Models\SupportTicket;
use App\Models\TeacherMeetingReport;
use App\Models\User;
use App\Models\WorkTask;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Quét toàn bộ route GET: Quản lý cơ sở A không được thấy dữ liệu của chi nhánh B ở bất kỳ trang / API nào.
 *
 * Dữ liệu hai chi nhánh mang mã nhận diện riêng (ZAMARK… / ZBMARK…). Đăng nhập Quản lý chi nhánh A rồi gọi mọi route GET
 * (route có tham số bản ghi được gọi với bản ghi của chi nhánh B): mã ZBMARK… xuất hiện trong props trang Inertia hoặc nội
 * dung trả về là lộ dữ liệu. Route mới tự được quét, nên lỗ mới bị chặn trước khi deploy.
 *
 * Phạm vi quét: props trang lần tải đầu và nội dung JSON / HTML / văn bản; file tải xuống (Excel, PDF) không đọc được
 * nên không quét.
 */
class BranchIsolationRouteSweepTest extends TestCase
{
    use RefreshDatabase;

    /** Route không quét, kèm lý do (route kỹ thuật, không trả dữ liệu nghiệp vụ). */
    private const SKIPPED_ROUTES = [
        'logout' => 'đăng xuất phiên đang quét',
        'impersonate.leave' => 'đổi phiên đăng nhập',
    ];

    private Branch $branchA;

    private Branch $branchB;

    private User $managerA;

    /** Route có tham số bản ghi: tiền tố tên route → loại bản ghi chi nhánh B dùng làm tham số. */
    private const RECORD_ROUTES = [
        'classes.' => 'class', 'teacher.' => 'class', 'crm.customers.' => 'customer', 'students.' => 'student',
        'tuition.students.' => 'student', 'tickets.' => 'ticket', 'penalties.' => 'penalty', 'tasks.' => 'task',
        'users.' => 'teacher', 'kpi.evaluate' => 'teacher',
    ];

    /** @var array<string, int> id bản ghi chi nhánh B theo loại */
    private array $branchBRecords = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Chỉ cần props trang (viewData), không cần render HTML bằng Node.
        config(['inertia.ssr.enabled' => false]);
        config(['services.sepay.webhook_enabled' => true]);

        $this->branchA = Branch::create(['name' => 'Chi nhanh A', 'code' => 'SWP-A', 'is_active' => true]);
        $this->branchB = Branch::create(['name' => 'Chi nhanh B', 'code' => 'SWP-B', 'is_active' => true]);
        $this->managerA = $this->makeUser(Roles::MANAGER, $this->branchA, 'Quan ly A');

        $course = Course::create(['name' => 'Sweep Course', 'code' => 'SWP', 'total_lessons' => 24, 'tuition_fee' => 3000000, 'is_active' => true]);
        $this->seedBranch('A', $this->branchA, $course);
        $this->branchBRecords = $this->seedBranch('B', $this->branchB, $course);
    }

    public function test_branch_manager_sees_no_data_of_another_branch_on_any_get_route(): void
    {
        $this->assertNoForeignBranchData($this->managerA,
            ['ZAMARKSTUDENT', 'ZAMARKLEAD', 'ZAMARKCLASS', 'ZAMARKTICKET', 'ZAMARKPENALTY', 'ZAMARKMEETING']);
    }

    public function test_branch_academic_staff_sees_no_data_of_another_branch_on_any_get_route(): void
    {
        $this->assertNoForeignBranchData($this->makeUser(Roles::ACADEMIC_STAFF, $this->branchA, 'Hoc vu A'),
            ['ZAMARKSTUDENT', 'ZAMARKCLASS', 'ZAMARKPENALTY', 'ZAMARKMEETING']);
    }

    /**
     * Gọi mọi route GET với tư cách $viewer (chi nhánh A): không trang nào được chứa mã ZBMARK…, và mỗi mã trong $ownMarkers
     * phải hiện ở ít nhất một trang (đối chứng: bộ quét thật sự đọc được dữ liệu).
     *
     * @param  list<string>  $ownMarkers
     */
    private function assertNoForeignBranchData(User $viewer, array $ownMarkers): void
    {
        $leaks = [];
        $seenOwn = [];
        $visited = 0;

        foreach (Route::getRoutes() as $route) {
            $url = $this->urlFor($route);
            if ($url === null) {
                continue;
            }

            $response = $this->actingAs($viewer)->get($url);
            $visited++;
            $status = $response->baseResponse->getStatusCode();
            if ($status < 200 || $status >= 300) {
                continue;
            }

            $content = $this->readableContent($response->baseResponse);
            preg_match_all('/ZBMARK[A-Z]+/', $content, $foreign);
            if ($foreign[0] !== []) {
                $leaks[] = sprintf('%s (%s): %s', $url, $route->getName() ?? '-', implode(', ', array_unique($foreign[0])));
            }
            preg_match_all('/ZAMARK[A-Z]+/', $content, $own);
            $seenOwn = array_merge($seenOwn, $own[0]);
        }

        $this->assertGreaterThan(100, $visited, 'Phải quét được phần lớn route GET.');
        foreach ($ownMarkers as $marker) {
            $this->assertContains($marker, $seenOwn, "{$viewer->name} phải thấy {$marker} ở ít nhất một trang.");
        }
        $this->assertSame([], $leaks, "{$viewer->name} (chi nhánh A) thấy dữ liệu chi nhánh B:\n".implode("\n", $leaks));
    }

    /** URL để quét, hoặc null khi bỏ qua (không phải GET, tham số không biết điền, route kỹ thuật). */
    private function urlFor(RoutingRoute $route): ?string
    {
        if (! in_array('GET', $route->methods(), true) || isset(self::SKIPPED_ROUTES[$route->getName()])) {
            return null;
        }
        $uri = $route->uri();
        if (str_starts_with($uri, '_') || str_starts_with($uri, 'storage/') || str_starts_with($uri, 'uploads/')) {
            return null;
        }

        $record = null;
        foreach (self::RECORD_ROUTES as $prefix => $type) {
            if (str_starts_with((string) $route->getName(), $prefix)) {
                $record = $this->branchBRecords[$type];
                break;
            }
        }
        // Chỉ điền được một tham số bắt buộc (id bản ghi chi nhánh B); route nhiều tham số hoặc chưa ánh xạ thì bỏ qua.
        $required = preg_match_all('/\{[^}?]+\}/', $uri);
        if ($required > ($record === null ? 0 : 1)) {
            return null;
        }
        $uri = preg_replace('/\{[^}?]+\}/', (string) $record, preg_replace('/\{[^}]+\?\}/', '', $uri));

        return '/'.trim(preg_replace('#/+#', '/', $uri), '/');
    }

    /** Props trang Inertia (kể cả dữ liệu không hiện ra màn hình) hoặc nội dung JSON / HTML / văn bản. */
    private function readableContent(SymfonyResponse $response): string
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return '';
        }
        if ($response instanceof IlluminateResponse && $response->original instanceof View && isset($response->original->getData()['page'])) {
            return (string) json_encode($response->original->getData()['page'], JSON_UNESCAPED_UNICODE);
        }
        $type = (string) $response->headers->get('Content-Type');
        if ($type !== '' && ! preg_match('#json|html|text|csv|xml#', $type)) {
            return '';
        }

        return (string) $response->getContent();
    }

    /**
     * Dữ liệu một chi nhánh, mỗi bản ghi mang mã Z{A|B}MARK<LOẠI>.
     *
     * @return array<string, int> id bản ghi theo loại
     */
    private function seedBranch(string $key, Branch $branch, Course $course): array
    {
        $mark = fn (string $type) => "Z{$key}MARK{$type}";
        $teacher = $this->makeUser(Roles::TEACHER_FULLTIME, $branch, 'GV '.$mark('TEACHER'));
        $staff = $this->makeUser(Roles::ACADEMIC_STAFF, $branch, 'HV '.$mark('ACADEMICSTAFF'));

        $class = ClassModel::create([
            'course_id' => $course->id, 'branch_id' => $branch->id, 'name' => $mark('CLASS'), 'code' => "SWP-{$key}1",
            'teacher_id' => $teacher->id, 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2),
            'max_capacity' => 12, 'status' => 'active',
        ]);
        $student = Student::create(['code' => "HV-SWP-{$key}", 'name' => $mark('STUDENT'), 'phone' => $key === 'A' ? '0901000001' : '0902000002',
            'branch_id' => $branch->id, 'status' => 'studying']);
        $customer = CrmCustomer::create(['code' => "KH-SWP-{$key}", 'name' => $mark('LEAD'), 'phone' => $key === 'A' ? '0911000001' : '0912000002',
            'stage' => 'consulting', 'branch_id' => $branch->id]);
        $ticket = SupportTicket::create([
            'code' => "TK-SWP-{$key}", 'title' => $mark('TICKET'), 'category' => 'technical_issue', 'priority' => 'medium',
            'description' => 'Mô tả', 'creator_id' => $teacher->id, 'status' => 'open',
        ]);
        $penalty = Penalty::create([
            'code' => "BB-SWP-{$key}", 'user_id' => $teacher->id, 'violation_type' => $mark('PENALTY'),
            'error_category' => 'operations', 'violation_date' => today()->toDateString(), 'status' => 'pending',
        ]);
        TeacherMeetingReport::create([
            'week_start' => now()->startOfWeek(), 'teacher_id' => $teacher->id, 'class_id' => $class->id, 'author_id' => $staff->id,
            'class_note' => $mark('MEETING'), 'status' => 'handled',
        ]);
        $task = WorkTask::create([
            'title' => $mark('TASK'), 'creator_id' => $staff->id, 'assignee_id' => $teacher->id, 'due_date' => today(),
            'task_type' => 'one_time', 'branch_id' => $branch->id, 'class_id' => $class->id, 'status' => 'new',
        ]);
        StaffReport::create(['user_id' => $staff->id, 'type' => 'journal', 'title' => $mark('JOURNAL'), 'content' => 'Sự vụ',
            'severity' => 'urgent', 'report_date' => today()->toDateString(), 'status' => 'open', 'class_id' => $class->id]);

        return [
            'class' => $class->id, 'customer' => $customer->id, 'student' => $student->id, 'ticket' => $ticket->id,
            'penalty' => $penalty->id, 'task' => $task->id, 'teacher' => $teacher->id,
        ];
    }

    private function makeUser(string $role, Branch $branch, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'branch_id' => $branch->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
