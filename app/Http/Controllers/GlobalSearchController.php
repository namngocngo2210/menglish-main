<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use App\Models\CrmCustomer;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ô tìm kiếm chung trên topbar: tìm khách CRM, học viên, lớp học theo tên / mã / SĐT.
 * Mỗi nhóm chỉ tìm khi người dùng có quyền xem module đó và luôn đi qua scope
 * phân quyền dữ liệu của model (visibleTo), nên không lộ bản ghi ngoài phạm vi.
 */
class GlobalSearchController extends Controller
{
    private const LIMIT = 20;

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $term = trim((string) $request->query('q', ''));
        $results = ['customers' => collect(), 'students' => collect(), 'classes' => collect()];
        $searched = [];

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

        return view('search.index', [
            'term' => $term,
            'results' => $results,
            'searched' => $searched,
            'total' => collect($results)->sum(fn ($items) => $items->count()),
        ]);
    }
}
