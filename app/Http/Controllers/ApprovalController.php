<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Support\Approvals\ApprovableSource;
use App\Support\Approvals\ApprovalInboxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * "Việc cần duyệt" (IX-5): một hộp gom mọi yêu cầu chờ user duyệt. Chỉ đọc / uỷ quyền qua ApprovableSource,
 * nghiệp vụ duyệt vẫn nằm ở module gốc. Vào được khi duyệt được ít nhất 1 nguồn (403 nếu không).
 */
class ApprovalController extends Controller
{
    use RendersModals;

    /** Số mục hiện tối đa mỗi nguồn (còn lại: "Xem tất cả" sang màn gốc). */
    public const PER_SOURCE = 15;

    public function __construct(private readonly ApprovalInboxService $inbox) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($this->inbox->canView($user), 403, 'Bạn không có quyền duyệt mục nào.');

        $groups = $this->inbox->groups($user);
        $group = array_key_exists((string) $request->query('group'), $groups) ? (string) $request->query('group') : null;
        $sections = $this->inbox->sections($user, $group, self::PER_SOURCE);
        $total = array_sum(array_column($groups, 'count'));

        return view('approvals.index', compact('groups', 'group', 'sections', 'total'));
    }

    /** Chi tiết 1 mục (modal) + Duyệt / Từ chối nếu nguồn hỗ trợ. */
    public function show(Request $request, string $source, int $id): Response
    {
        $user = $request->user();
        abort_unless($this->inbox->canView($user), 403);
        $found = $this->inbox->find($user, $source, $id);
        abort_unless($found !== null, 404, 'Mục này không còn chờ duyệt hoặc ngoài phạm vi của bạn.');
        [$approvable, $item] = $found;

        return $this->modalView('approvals.show', [
            'source' => $approvable,
            'item' => $item,
            'canApprove' => $approvable->supports($user, ApprovableSource::APPROVE),
            'canReject' => $approvable->supports($user, ApprovableSource::REJECT),
        ]);
    }

    /**
     * Duyệt / từ chối hàng loạt (hoặc 1 mục từ modal chi tiết, `single=1`). Mỗi mục một transaction; trả kết quả
     * từng dòng. htmx: fragment kết quả + HX-Trigger {close-modal, toast, approvals-changed}.
     */
    public function bulk(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->inbox->canView($user), 403);

        $validator = Validator::make($request->all(), [
            'action' => ['required', 'in:'.ApprovableSource::APPROVE.','.ApprovableSource::REJECT],
            'items' => ['required', 'array', 'min:1', 'max:'.ApprovalInboxService::MAX_BULK],
            'items.*' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'required_if:action,'.ApprovableSource::REJECT, 'string', 'max:1000'],
        ], [
            'items.required' => 'Chưa chọn mục nào.',
            'items.max' => 'Mỗi lần xử lý tối đa '.ApprovalInboxService::MAX_BULK.' mục.',
            'reason.required_if' => 'Vui lòng nhập lý do từ chối.',
        ]);

        if ($validator->fails()) {
            $message = (string) $validator->errors()->first();
            if (! $this->isModalRequest()) {
                return back()->withErrors($validator);
            }

            return response()->view('approvals._results', ['results' => [], 'error' => $message], 422)
                ->header('HX-Trigger', json_encode(['toast' => ['message' => $message, 'type' => 'error']]));
        }

        $data = $validator->validated();
        $results = $this->inbox->process($user, $data['action'], $data['items'], $data['reason'] ?? null);
        $okCount = count(array_filter($results, fn (array $r) => $r['ok']));
        $failed = array_values(array_filter($results, fn (array $r) => ! $r['ok']));
        $verb = $data['action'] === ApprovableSource::APPROVE ? 'duyệt' : 'từ chối';
        $summary = count($results) === 1
            ? $results[0]['message']
            : 'Đã '.$verb.' '.$okCount.'/'.count($results).' mục'.($failed ? ', '.count($failed).' mục không xử lý được.' : '.');

        if (! $this->isModalRequest()) {
            return redirect()->route('approvals.index')->with($failed ? 'warning' : 'status', $summary)->with('approval_results', $results);
        }

        $trigger = [
            'close-modal' => true,
            'toast' => ['message' => $summary, 'type' => $failed ? ($okCount ? 'warning' : 'error') : 'success'],
            'approvals-changed' => true,
        ];

        // Từ modal chi tiết: không có vùng kết quả, chỉ toast.
        if ($request->boolean('single')) {
            return response()->noContent()->header('HX-Trigger', json_encode($trigger));
        }

        return response()->view('approvals._results', ['results' => $results, 'error' => null])
            ->header('HX-Trigger', json_encode($trigger));
    }
}
