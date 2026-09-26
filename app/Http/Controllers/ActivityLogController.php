<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    /** Nhóm lọc nhanh theo module (mockup: Tất cả / CRM / Giáo trình / Lớp & Điểm danh / Phân quyền …). */
    public const MODULE_GROUPS = [
        'crm' => ['label' => 'CRM', 'logs' => ['CRM & Leads', 'Khảo sát & Đề thi']],
        'syllabus' => ['label' => 'Giáo trình', 'logs' => ['Giáo trình & Syllabus']],
        'class' => ['label' => 'Lớp & Điểm danh', 'logs' => ['Học viên & Lớp học']],
        'permission' => ['label' => 'Phân quyền', 'logs' => ['Người dùng & Phân quyền', 'Tài khoản & Hồ sơ']],
        'finance' => ['label' => 'Học phí', 'logs' => ['Học phí & Thu chi']],
        'payroll' => ['label' => 'Lương & Chấm công', 'logs' => ['Bảng lương & Chấm công']],
        'tasks' => ['label' => 'Công việc & Ticket', 'logs' => ['Quản lý công việc', 'Ticket hỗ trợ']],
        'system' => ['label' => 'Cấu hình', 'logs' => ['Cấu hình hệ thống', 'Quản lý Media']],
    ];

    public function index(Request $request): View
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ], ['date_to.after_or_equal' => 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.']);

        $logs = $this->filteredQuery($request)
            ->with(['causer.roles'])
            ->paginate($request->perPage(10))
            ->withQueryString();

        // Module choices & Users for filter dropdowns
        $logNames = [
            'CRM & Leads',
            'Học phí & Thu chi',
            'Học viên & Lớp học',
            'Khảo sát & Đề thi',
            'Giáo trình & Syllabus',
            'Bảng lương & Chấm công',
            'Quản lý công việc',
            'Ticket hỗ trợ',
            'Quản lý Media',
            'Cấu hình hệ thống',
            'Người dùng & Phân quyền',
            'Tài khoản & Hồ sơ',
        ];

        // Merge any extra log names from DB
        $dbLogNames = Activity::query()->whereNotNull('log_name')->distinct()->pluck('log_name')->toArray();
        $allLogNames = array_values(array_unique(array_merge($logNames, $dbLogNames)));

        $events = Activity::query()->whereNotNull('event')->distinct()->pluck('event');
        $users = User::select('id', 'name', 'email')->orderBy('name')->get();

        // Quick Stats
        $totalLogsToday = Activity::whereDate('created_at', today())->count();
        $totalLogsCount = Activity::count();
        $activeUsersToday = Activity::whereDate('created_at', today())->distinct('causer_id')->count('causer_id');

        $canUndo = (bool) $request->user()?->hasRole('admin');

        return view('activity-logs.index', compact(
            'logs',
            'allLogNames',
            'events',
            'users',
            'totalLogsToday',
            'totalLogsCount',
            'activeUsersToday',
            'canUndo'
        ));
    }

    /**
     * Xuất nhật ký theo bộ lọc hiện tại ra CSV (UTF-8 BOM, mở trực tiếp bằng Excel).
     */
    public function export(Request $request): StreamedResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'format' => ['nullable', 'in:xlsx,csv'],
        ]);

        $query = $this->filteredQuery($request)->with('causer');
        $headings = ['ID', 'Thời điểm', 'Người thực hiện', 'Email', 'Phân hệ', 'Hành động', 'Nội dung', 'Đối tượng', 'Trường thay đổi (trước → sau)', 'IP'];
        $row = fn (Activity $log) => [
            $log->id,
            $log->created_at?->format('d/m/Y H:i:s'),
            $log->causer?->name ?? 'Hệ thống tự động',
            $log->causer?->email,
            $log->log_name,
            Audit::eventLabel($log->event),
            $log->description,
            $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : '',
            collect(self::diff($log))
                ->map(fn ($r, $field) => $field.': '.self::stringify($r['old']).' → '.self::stringify($r['new']))
                ->implode('; '),
            $log->properties['ip'] ?? '',
        ];

        // Mặc định Excel (.xlsx) theo mockup "Xuất Excel"; ?format=csv giữ bản CSV streaming (dữ liệu lớn).
        if ($request->query('format', 'xlsx') === 'xlsx') {
            $rows = [];
            $query->limit(20000)->get()->each(function (Activity $log) use (&$rows, $row) {
                $rows[] = $row($log);
            });

            return \App\Exports\ArrayExport::download('nhat-ky-van-hanh', $headings, $rows, 'xlsx');
        }
        $filename = 'nhat-ky-van-hanh-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID', 'Thời điểm', 'Người thực hiện', 'Email', 'Phân hệ', 'Hành động', 'Nội dung', 'Đối tượng', 'Trường thay đổi (trước → sau)', 'IP']);

            $query->chunk(500, function ($logs) use ($out) {
                foreach ($logs as $log) {
                    fputcsv($out, [
                        $log->id,
                        $log->created_at?->format('d/m/Y H:i:s'),
                        $log->causer?->name ?? 'Hệ thống tự động',
                        $log->causer?->email,
                        $log->log_name,
                        Audit::eventLabel($log->event),
                        $log->description,
                        $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : '',
                        collect(self::diff($log))
                            ->map(fn ($row, $field) => $field.': '.self::stringify($row['old']).' → '.self::stringify($row['new']))
                            ->implode('; '),
                        $log->properties['ip'] ?? '',
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * "Hoàn tác": khôi phục giá trị cũ (old) của một thao tác cập nhật đơn giản
     * trên model trong danh sách cho phép (Audit::MODELS[...]['undo']). Chỉ Admin.
     * Từ chối nếu bản ghi đã bị sửa tiếp sau thao tác đó (tránh ghi đè dữ liệu mới).
     */
    public function undo(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403, 'Chỉ Admin được hoàn tác thao tác.');

        $log = Activity::query()->findOrFail($id);
        [$model, $fields, $error] = self::undoPlan($log);

        if ($error) {
            return back()->withErrors(['undo' => $error]);
        }

        $currentValues = $model::logChanges($model);
        $newValues = (array) ($log->properties['attributes'] ?? []);
        foreach ($fields as $field) {
            if (self::stringify($currentValues[$field] ?? null) !== self::stringify($newValues[$field] ?? null)) {
                return back()->withErrors(['undo' => "Không thể hoàn tác: trường \"{$field}\" đã bị thay đổi sau thao tác này."]);
            }
        }

        $old = (array) $log->properties['old'];
        DB::transaction(function () use ($model, $fields, $old, $log) {
            foreach ($fields as $field) {
                $model->setAttribute($field, $old[$field]);
            }
            Audit::describe("Hoàn tác thao tác nhật ký #{$log->id}");
            $model->save();
        });

        return back()->with('status', "Đã hoàn tác thao tác #{$log->id}: khôi phục ".count($fields).' trường.');
    }

    /**
     * Kiểm tra một dòng nhật ký có hoàn tác được không.
     *
     * @return array{0: ?Model, 1: string[], 2: ?string}
     */
    public static function undoPlan(Activity $log): array
    {
        if ($log->event !== 'updated' || ! $log->subject_type || ! $log->subject_id) {
            return [null, [], 'Chỉ hoàn tác được thao tác "Cập nhật" trên dữ liệu.'];
        }

        $allowed = Audit::undoableAttributes($log->subject_type);
        $old = (array) ($log->properties['old'] ?? []);
        $fields = array_values(array_intersect(array_keys($old), $allowed));

        if (empty($old) || empty($fields)) {
            return [null, [], 'Thao tác này không có trường nào được phép hoàn tác.'];
        }

        // Chỉ hoàn tác khi MỌI trường thay đổi đều nằm trong danh sách cho phép
        // (thao tác "đơn giản"); tránh hoàn tác một nửa nghiệp vụ.
        if (count($fields) !== count($old)) {
            return [null, [], 'Thao tác có trường nghiệp vụ không được phép hoàn tác tự động.'];
        }

        foreach ($fields as $field) {
            if ($old[$field] === \App\Support\SensitiveData::MASK) {
                return [null, [], 'Thao tác chứa dữ liệu nhạy cảm đã che, không thể hoàn tác.'];
            }
        }

        $class = $log->subject_type;
        $model = class_exists($class) ? $class::query()->find($log->subject_id) : null;
        if (! $model) {
            return [null, [], 'Bản ghi gốc không còn tồn tại.'];
        }

        return [$model, $fields, null];
    }

    /**
     * Danh sách trường thay đổi của một dòng nhật ký: [field => ['old' => ..., 'new' => ...]].
     * Hỗ trợ cả dữ liệu model (old/attributes) và dạng cũ ghi tay (before/after).
     */
    public static function diff(Activity $log): array
    {
        $props = $log->properties;
        $old = (array) ($props['old'] ?? $props['before'] ?? []);
        $new = (array) ($props['attributes'] ?? $props['after'] ?? []);

        if (empty($old) && empty($new)) {
            return [];
        }

        $rows = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
            $rows[$field] = ['old' => $old[$field] ?? null, 'new' => $new[$field] ?? null];
        }

        return $rows;
    }

    public static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'Có' : 'Không',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }

    private function filteredQuery(Request $request): Builder
    {
        $query = Activity::query()->latest()->orderByDesc('id');

        if ($request->filled('log_name')) {
            $query->where('log_name', $request->input('log_name'));
        }

        if (($group = $request->input('module')) && isset(self::MODULE_GROUPS[$group])) {
            $query->whereIn('log_name', self::MODULE_GROUPS[$group]['logs']);
        }

        if ($request->filled('event')) {
            $query->where('event', $request->input('event'));
        }

        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->input('causer_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            // "Mã bản ghi": #123 hoặc số → khớp id đối tượng / id nhật ký.
            $recordId = preg_match('/^#?(\d+)$/', $search, $m) ? (int) $m[1] : null;
            $query->where(function ($sub) use ($search, $recordId) {
                if ($recordId) {
                    $sub->where('subject_id', $recordId)->orWhere('id', $recordId)->orWhere('causer_id', $recordId);
                }
                $sub->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('batch_uuid', $search)
                    ->orWhere('log_name', 'like', "%{$search}%")
                    ->orWhere('event', 'like', "%{$search}%")
                    ->orWhereHas('causer', function ($c) use ($search) {
                        $c->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }
}
