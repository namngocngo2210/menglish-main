<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Support\Approvals\ApprovableSource;
use App\Support\Approvals\ApprovalInboxService;
use App\Support\Approvals\ApprovalItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Việc cần duyệt" (IX-5): một hộp gom mọi yêu cầu chờ user duyệt. Chỉ đọc / uỷ quyền qua ApprovableSource,
 * nghiệp vụ duyệt vẫn nằm ở module gốc. Chỉ Admin vào được (ApprovalInboxService::allowsModule, 403 nếu không).
 */
class ApprovalController extends Controller
{
    use RendersModals;

    /** Số mục hiện tối đa mỗi nguồn (còn lại: "Xem tất cả" sang màn gốc). */
    public const PER_SOURCE = 15;

    public function __construct(private readonly ApprovalInboxService $inbox) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->inbox->canView($user), 403, 'Bạn không có quyền duyệt mục nào.');

        $groups = $this->inbox->groups($user);
        $group = array_key_exists((string) $request->query('group'), $groups) ? (string) $request->query('group') : null;
        $sections = $this->inbox->sections($user, $group, self::PER_SOURCE);

        return Inertia::render('Approvals/Index', [
            'groups' => collect($groups)->map(fn (array $g, string $slug) => ['slug' => $slug, 'label' => $g['label'], 'count' => $g['count']])->values()->all(),
            'group' => $group,
            'total' => array_sum(array_column($groups, 'count')),
            'sections' => array_map(fn (array $section) => [
                'key' => $section['source']->key(),
                'label' => $section['source']->label(),
                'group' => $section['source']->group(),
                'indexUrl' => $section['source']->indexUrl(),
                'count' => $section['count'],
                'approve' => $section['approve'],
                'reject' => $section['reject'],
                'items' => $section['items']->map(fn (ApprovalItem $item) => $this->itemData($item))->values()->all(),
            ], $sections),
            // Kết quả lần duyệt / từ chối hàng loạt vừa xong (liệt kê mục không xử lý được).
            'results' => session('approval_results', []),
        ]);
    }

    /** Chi tiết 1 mục (modal) + Duyệt / Từ chối nếu nguồn hỗ trợ. */
    public function show(Request $request, string $source, int $id): Response
    {
        $user = $request->user();
        abort_unless($this->inbox->canView($user), 403);
        $found = $this->inbox->find($user, $source, $id);
        abort_unless($found !== null, 404, 'Mục này không còn chờ duyệt hoặc ngoài phạm vi của bạn.');
        [$approvable, $item] = $found;

        return $this->modalPage('Approvals/Show', [
            'source' => ['label' => $approvable->label(), 'group' => $approvable->group()],
            'item' => [
                ...$this->itemData($item),
                'url' => $item->url,
                'modalUrl' => $item->modalUrl,
                'meta' => collect($item->meta)->map(fn ($value, $label) => ['label' => (string) $label, 'value' => (string) $value])->values()->all(),
            ],
            'canApprove' => $approvable->supports($user, ApprovableSource::APPROVE),
            'canReject' => $approvable->supports($user, ApprovableSource::REJECT),
        ]);
    }

    /**
     * Duyệt / từ chối hàng loạt (hoặc 1 mục từ modal chi tiết, `single=1`). Mỗi mục một transaction; trả kết quả
     * từng dòng. Từ trang danh sách / modal (X-Remote-Modal): quay lại trang đang mở kèm thông báo (+ kết quả từng mục
     * khi xử lý hàng loạt); request thường: về "Việc cần duyệt" như cũ.
     */
    public function bulk(Request $request): RedirectResponse
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
            return back()->withErrors($validator);
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

        $redirect = back()->with($failed ? ($okCount ? 'warning' : 'error') : 'status', $summary);

        // Từ modal chi tiết: không có vùng kết quả, chỉ thông báo.
        return $request->boolean('single') ? $redirect : $redirect->with('approval_results', $results);
    }

    /** @return array<string, mixed> */
    private function itemData(ApprovalItem $item): array
    {
        return [
            'ref' => $item->ref(),
            'source' => $item->source,
            'id' => $item->id,
            'title' => $item->title,
            'subtitle' => $item->subtitle,
            'flag' => $item->flag,
            'amount' => $item->amount,
            'created_at' => $item->createdAt?->toIso8601String(),
            'created_ago' => $item->createdAt?->diffForHumans(),
        ];
    }
}
