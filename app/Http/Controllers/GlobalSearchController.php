<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\CrmCustomer;
use App\Models\Student;
use App\Support\Navigation\SidebarMenu;
use App\Support\StatusLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ô tìm kiếm chung trên topbar: tìm màn hình theo tên (menu, tab, Cài đặt — chỉ màn user được mở) và
 * khách CRM, học viên, lớp học theo tên / mã / SĐT.
 * Mỗi nhóm chỉ tìm khi người dùng có quyền xem module đó và luôn đi qua scope
 * phân quyền dữ liệu của model (visibleTo), nên không lộ bản ghi ngoài phạm vi.
 */
class GlobalSearchController extends Controller
{
    private const LIMIT = 20;

    public function __invoke(Request $request, SidebarMenu $menu): Response
    {
        $user = $request->user();
        $term = trim((string) $request->query('q', ''));
        $results = ['customers' => collect(), 'students' => collect(), 'classes' => collect()];
        $searched = [];
        $screens = $menu->searchScreens($user, $term, $request);

        if (mb_strlen($term) >= 2) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $digits = preg_replace('/\D+/', '', $term);
            $phoneLike = strlen($digits) >= 3 ? '%'.$digits.'%' : null;

            if ($user->can('lead.view')) {
                $searched[] = 'customers';
                $results['customers'] = CrmCustomer::query()->visibleTo($user)
                    ->with('branch:id,name')
                    ->where(function (Builder $q) use ($like, $phoneLike) {
                        $q->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('email', 'like', $like);
                        if ($phoneLike) {
                            $q->orWhere('phone', 'like', $phoneLike)->orWhere('phone_normalized', 'like', $phoneLike)
                                ->orWhere('parent_phone', 'like', $phoneLike);
                        }
                    })
                    ->latest()->limit(self::LIMIT)->get();
            }

            if ($user->can('student.view')) {
                $searched[] = 'students';
                $results['students'] = Student::query()->visibleTo($user)
                    ->with(['currentClass:id,name,code', 'branch:id,name'])
                    ->where(function (Builder $q) use ($like, $phoneLike) {
                        $q->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('email', 'like', $like);
                        if ($phoneLike) {
                            $q->orWhere('phone', 'like', $phoneLike);
                        }
                    })
                    ->orderBy('name')->limit(self::LIMIT)->get();
            }

            if ($user->can('class.view')) {
                $searched[] = 'classes';
                $results['classes'] = ClassModel::query()->visibleTo($user)
                    ->with(['branch:id,name', 'teacher:id,name'])
                    ->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like))
                    ->orderBy('code')->limit(self::LIMIT)->get();
            }
        }

        return Inertia::render('Search/Index', [
            'term' => $term,
            'searched' => $searched,
            'screens' => array_map(fn (array $screen) => ['title' => $screen['title'], 'url' => $screen['url']], $screens),
            'customers' => $results['customers']->map(fn (CrmCustomer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'code' => $customer->code,
                'phone' => $customer->phone,
                'branch' => $customer->branch?->name,
                'stage_label' => $customer->stage_label,
            ])->all(),
            'students' => $results['students']->map(fn (Student $student) => [
                'id' => $student->id,
                'name' => $student->name,
                'code' => $student->code,
                'phone' => $student->phone,
                'class' => $student->currentClass?->name,
                'status_label' => $student->status_label,
            ])->all(),
            'classes' => $results['classes']->map(fn (ClassModel $class) => [
                'id' => $class->id,
                'name' => $class->name,
                'code' => $class->code,
                'branch' => $class->branch?->name,
                'teacher' => $class->teacher?->name,
                'status_label' => StatusLabel::for($class->status),
            ])->all(),
            'total' => collect($results)->sum(fn ($items) => $items->count()) + count($screens),
        ]);
    }
}
