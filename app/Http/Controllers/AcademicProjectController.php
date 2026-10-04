<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RendersModals;
use App\Models\AcademicProject;
use App\Models\AcademicProjectMilestone;
use App\Models\AcademicProjectUpdate;
use App\Models\AdminNotification;
use App\Models\Penalty;
use App\Models\SlaEvent;
use App\Models\User;
use App\Services\Sla\Sla;
use App\Support\Rbac;
use App\Support\ReportPeriod;
use App\Support\Ui;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dự án học thuật — soạn sách, xây chương trình (chủ dự án 04/10/2026): họp thống nhất → chốt tiến độ (mốc, deadline,
 * khối lượng, người nhận) → thành viên cập nhật tiến độ (khối lượng xong, link sản phẩm, khó khăn) → Học thuật phản hồi;
 * mốc trễ deadline tự lập biên bản chờ giải trình (AcademicProjectSlaService, SLA academic.milestone_late).
 *
 * Quyền: academic_project.view — xem & cập nhật dự án mình tham gia; view_all — xem mọi dự án + báo cáo;
 * manage — tạo / sửa / xóa dự án, chốt tiến độ, quản lý mốc, phản hồi. Người phụ trách dự án cũng phản hồi được.
 */
class AcademicProjectController extends Controller
{
    use RendersModals;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(array_keys(AcademicProject::TYPES))],
            'status' => ['nullable', Rule::in(array_keys(AcademicProject::STATUSES))],
            'owner_id' => ['nullable', 'integer'],
        ]);

        $projects = AcademicProject::query()->visibleTo($user)
            ->with(['owner:id,name', 'milestones'])
            ->withCount('members')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['owner_id'] ?? null, fn ($q, $id) => $q->where('owner_id', $id))
            ->orderByRaw("case status when 'active' then 0 when 'planning' then 1 when 'paused' then 2 else 3 end")
            ->orderBy('deadline')->latest('id')
            ->paginate($request->perPage(15))->withQueryString()
            ->through(fn (AcademicProject $p) => $this->summary($p));

        return Inertia::render('AcademicProjects/Index', [
            'projects' => $projects,
            'types' => Ui::options(AcademicProject::TYPES),
            'statuses' => Ui::options(AcademicProject::STATUSES),
            'owners' => Ui::options($this->staff()->get(['id', 'name']), 'name'),
            'canManage' => $user->can('academic_project.manage'),
        ]);
    }

    public function create(): Response
    {
        return $this->modalPage('AcademicProjects/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedProject($request);
        $project = DB::transaction(function () use ($data, $request) {
            $project = AcademicProject::create(collect($data)->except('member_ids')->all() + [
                'status' => 'planning',
                'created_by' => $request->user()->id,
            ]);
            $project->members()->sync($this->memberIds($data));

            return $project;
        });
        $this->notifyUsers($project->members()->pluck('users.id')->all(), $request->user(), 'academic_project_member',
            "Bạn tham gia dự án {$project->name}", 'Xem kế hoạch, mốc và cập nhật tiến độ phần việc của bạn.', $project);

        return redirect()->route('academic-projects.show', $project->id)->with('success', "Đã tạo dự án {$project->code}. Thêm các mốc rồi bấm \"Chốt tiến độ\".");
    }

    public function show(Request $request, int $id): Response
    {
        $user = $request->user();
        $project = AcademicProject::visibleTo($user)
            ->with(['owner:id,name', 'creator:id,name', 'locker:id,name', 'members:id,name', 'milestones.assignee:id,name'])
            ->findOrFail($id);
        $canManage = $user->can('academic_project.manage');
        $canUpdate = $project->isOpen() && ($canManage || $project->involves($user));

        $updates = $project->updates()->with(['user:id,name', 'milestone:id,title,unit', 'responder:id,name'])->limit(100)->get();

        return Inertia::render('AcademicProjects/Show', [
            'project' => $this->summary($project) + [
                'description' => $project->description,
                'owner_id' => $project->owner_id,
                'member_ids' => $project->members->pluck('id')->all(),
                'members' => $project->members->map(fn (User $m) => ['id' => $m->id, 'name' => $m->name])->values(),
                'kickoff_notes' => $project->kickoff_notes,
                'kickoff_link' => $project->kickoff_link,
                'plan_locked_at' => $project->plan_locked_at?->toIso8601String(),
                'locker' => $project->locker?->name,
                'creator' => $project->creator?->name,
                'completed_at' => $project->completed_at?->toIso8601String(),
            ],
            'milestones' => $project->milestones->map(fn (AcademicProjectMilestone $m) => $this->milestoneRow($m, $user, $canManage))->values(),
            'updates' => $updates->map(fn (AcademicProjectUpdate $u) => [
                'id' => $u->id,
                'user' => $u->user?->name,
                'milestone' => $u->milestone?->title,
                'created_at' => $u->created_at->toIso8601String(),
                'quantity' => $u->quantity_done !== null && $u->milestone
                    ? AcademicProjectMilestone::formatQuantity($u->quantity_done).' '.$u->milestone->unit : null,
                'content' => $u->content,
                'difficulties' => $u->difficulties,
                'links' => $u->links ?? [],
                'marks_complete' => $u->marks_complete,
                'response' => $u->response,
                'responder' => $u->responder?->name,
                'responded_at' => $u->responded_at?->toIso8601String(),
            ])->values(),
            'penalties' => $this->penalties($project->milestones->pluck('id')->all())->values(),
            'staff' => Ui::options($this->staff()->get(['id', 'name']), 'name'),
            'milestoneStatuses' => Ui::options(AcademicProjectMilestone::STATUSES),
            'canManage' => $canManage,
            'canUpdate' => $canUpdate,
            'canRespond' => $canManage || $project->owner_id === $user->id,
            'graceHours' => Sla::value(AcademicProjectMilestone::SLA_RULE),
            'slaEnabled' => Sla::enabled(AcademicProjectMilestone::SLA_RULE),
        ]);
    }

    public function edit(int $id): Response
    {
        $project = AcademicProject::with('members:id')->findOrFail($id);

        return $this->modalPage('AcademicProjects/Form', $this->formProps() + ['project' => [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'type' => $project->type,
            'owner_id' => $project->owner_id,
            'member_ids' => $project->members->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'start_date' => $project->start_date?->toDateString(),
            'deadline' => $project->deadline?->toDateString(),
            'description' => $project->description,
            'kickoff_notes' => $project->kickoff_notes,
            'kickoff_link' => $project->kickoff_link,
        ]]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $project = AcademicProject::with('members:id')->findOrFail($id);
        $data = $this->validatedProject($request);
        $before = $project->members->pluck('id')->all();
        DB::transaction(function () use ($project, $data) {
            $project->update(collect($data)->except('member_ids')->all());
            $project->members()->sync($this->memberIds($data));
        });
        $added = array_diff($project->members()->pluck('users.id')->all(), $before);
        $this->notifyUsers($added, $request->user(), 'academic_project_member',
            "Bạn tham gia dự án {$project->name}", 'Xem kế hoạch, mốc và cập nhật tiến độ phần việc của bạn.', $project);

        return $this->modalSaved('Đã lưu dự án.', route('academic-projects.show', $project->id), 'success');
    }

    public function destroy(int $id): RedirectResponse
    {
        $project = AcademicProject::findOrFail($id);
        $project->delete();

        return redirect()->route('academic-projects.index')->with('success', "Đã xóa dự án {$project->code}.");
    }

    /** Chốt tiến độ sau buổi họp thống nhất: dự án chuyển "Đang thực hiện", từ đây mốc trễ hạn bị lập biên bản. */
    public function lock(Request $request, int $id): RedirectResponse
    {
        $project = AcademicProject::with(['milestones', 'members:id'])->findOrFail($id);
        if ($project->status !== 'planning') {
            return back()->with('error', 'Chỉ chốt tiến độ khi dự án đang lên kế hoạch.');
        }
        if ($project->milestones->isEmpty()) {
            return back()->with('error', 'Thêm ít nhất một mốc (deadline + khối lượng) trước khi chốt tiến độ.');
        }
        $project->update(['status' => 'active', 'plan_locked_at' => now(), 'plan_locked_by' => $request->user()->id]);
        $this->notifyUsers($this->participantIds($project), $request->user(), 'academic_project_locked',
            "Dự án {$project->name} đã chốt tiến độ", 'Kiểm tra mốc và deadline của bạn; mốc trễ hạn sẽ bị lập biên bản theo quy định.', $project);

        return back()->with('success', 'Đã chốt tiến độ. Dự án chuyển sang "Đang thực hiện".');
    }

    public function changeStatus(Request $request, int $id): RedirectResponse
    {
        $project = AcademicProject::findOrFail($id);
        $action = $request->validate(['action' => ['required', Rule::in(['pause', 'resume', 'complete', 'cancel'])]])['action'];
        [$from, $to, $message] = match ($action) {
            'pause' => [['active'], 'paused', 'Đã tạm dừng dự án (mốc không bị tính trễ khi tạm dừng).'],
            'resume' => [['paused'], 'active', 'Dự án tiếp tục thực hiện.'],
            'complete' => [['active', 'paused'], 'completed', 'Đã đóng dự án: hoàn thành.'],
            'cancel' => [AcademicProject::OPEN_STATUSES, 'cancelled', 'Đã hủy dự án.'],
        };
        if (! in_array($project->status, $from, true)) {
            return back()->with('error', 'Trạng thái hiện tại của dự án không cho phép thao tác này.');
        }
        $project->update(['status' => $to, 'completed_at' => $to === 'completed' ? now() : $project->completed_at]);

        return back()->with('success', $message);
    }

    public function storeMilestone(Request $request, int $id): RedirectResponse
    {
        $project = AcademicProject::findOrFail($id);
        $data = $this->validatedMilestone($request);
        $milestone = $project->milestones()->create($data + [
            'sort_order' => (int) $project->milestones()->max('sort_order') + 1,
            'completed_at' => $data['status'] === 'done' ? now() : null,
        ]);
        $this->afterAssign($project, $milestone, null, $request->user());

        return back()->with('success', 'Đã thêm mốc "'.$milestone->title.'".');
    }

    public function updateMilestone(Request $request, int $milestoneId): RedirectResponse
    {
        $milestone = AcademicProjectMilestone::with('project')->findOrFail($milestoneId);
        $data = $this->validatedMilestone($request);
        $previousAssignee = $milestone->assignee_id;
        $milestone->fill($data);
        if ($milestone->isDirty('due_date')) {
            $milestone->reminded_at = null;
        }
        if ($milestone->isDirty('status')) {
            $milestone->completed_at = $milestone->status === 'done' ? now() : null;
        }
        $milestone->save();
        $this->afterAssign($milestone->project, $milestone, $previousAssignee, $request->user());

        return back()->with('success', 'Đã lưu mốc "'.$milestone->title.'".');
    }

    public function destroyMilestone(int $milestoneId): RedirectResponse
    {
        $milestone = AcademicProjectMilestone::findOrFail($milestoneId);
        $milestone->delete();

        return back()->with('success', 'Đã xóa mốc "'.$milestone->title.'".');
    }

    /** Thành viên cập nhật tiến độ: việc đã làm, khối lượng xong đến nay (của mốc mình nhận), link sản phẩm, khó khăn. */
    public function storeUpdate(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();
        $project = AcademicProject::visibleTo($user)->with(['members:id', 'milestones'])->findOrFail($id);
        $canManage = $user->can('academic_project.manage');
        abort_unless($canManage || $project->involves($user), 403);
        if (! $project->isOpen()) {
            return back()->with('error', 'Dự án đã đóng, không cập nhật tiến độ được nữa.');
        }

        $data = $request->validate([
            'milestone_id' => ['nullable', 'integer'],
            'quantity_done' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'content' => ['required', 'string', 'max:5000'],
            'difficulties' => ['nullable', 'string', 'max:5000'],
            'links_text' => ['nullable', 'string', 'max:3000'],
            'marks_complete' => ['nullable', 'boolean'],
        ], [
            'content.required' => 'Nhập nội dung đã làm / tiến độ.',
            'quantity_done.numeric' => 'Khối lượng phải là số.',
        ]);

        $milestone = null;
        if (! empty($data['milestone_id'])) {
            $milestone = $project->milestones->firstWhere('id', (int) $data['milestone_id']);
            if (! $milestone) {
                throw ValidationException::withMessages(['milestone_id' => 'Mốc không thuộc dự án này.']);
            }
            if (! $canManage && $milestone->assignee_id !== $user->id) {
                throw ValidationException::withMessages(['milestone_id' => 'Bạn chỉ cập nhật được mốc mình được giao.']);
            }
        } elseif (isset($data['quantity_done']) || ! empty($data['marks_complete'])) {
            throw ValidationException::withMessages(['milestone_id' => 'Chọn mốc để ghi khối lượng hoàn thành.']);
        }
        $links = $this->parseLinks($data['links_text'] ?? null);

        DB::transaction(function () use ($project, $milestone, $user, $data, $links) {
            AcademicProjectUpdate::create([
                'academic_project_id' => $project->id,
                'milestone_id' => $milestone?->id,
                'user_id' => $user->id,
                'quantity_done' => $data['quantity_done'] ?? null,
                'content' => $data['content'],
                'difficulties' => $data['difficulties'] ?? null,
                'links' => $links ?: null,
                'marks_complete' => (bool) ($data['marks_complete'] ?? false),
            ]);
            if ($milestone) {
                if (isset($data['quantity_done'])) {
                    $milestone->done_quantity = (float) $data['quantity_done'];
                }
                if (! empty($data['marks_complete']) && ! $milestone->isDone()) {
                    $milestone->status = 'done';
                    $milestone->completed_at = now();
                    $milestone->done_quantity = max($milestone->done_quantity, $milestone->target_quantity);
                } elseif ($milestone->status === 'todo') {
                    $milestone->status = 'in_progress';
                }
                $milestone->save();
            }
        });

        if ($project->owner_id && $project->owner_id !== $user->id) {
            $hasIssue = filled($data['difficulties'] ?? null);
            AdminNotification::create([
                'user_id' => $project->owner_id,
                'type' => 'academic_project_update',
                'title' => ($hasIssue ? 'Khó khăn cần hỗ trợ — ' : 'Cập nhật tiến độ — ').$project->name,
                'message' => $user->name.($milestone ? " (mốc \"{$milestone->title}\")" : '').': '.str($hasIssue ? $data['difficulties'] : $data['content'])->limit(160),
                'data' => ['link' => route('academic-projects.show', $project->id)],
                'is_read' => false,
            ]);
        }

        return back()->with('success', $milestone?->isDone() && ! empty($data['marks_complete']) ? 'Đã cập nhật và đánh dấu hoàn thành mốc.' : 'Đã gửi cập nhật tiến độ.');
    }

    /** Học thuật (hoặc người phụ trách dự án) phản hồi khó khăn / cập nhật của thành viên. */
    public function respond(Request $request, int $updateId): RedirectResponse
    {
        $user = $request->user();
        $update = AcademicProjectUpdate::with('project')->findOrFail($updateId);
        abort_unless($update->project && ($user->can('academic_project.manage') || $update->project->owner_id === $user->id), 403);
        $data = $request->validate(['response' => ['required', 'string', 'max:3000']], ['response.required' => 'Nhập nội dung phản hồi.']);
        $update->update(['response' => $data['response'], 'responded_by' => $user->id, 'responded_at' => now()]);
        if ($update->user_id && $update->user_id !== $user->id) {
            AdminNotification::create([
                'user_id' => $update->user_id,
                'type' => 'academic_project_response',
                'title' => 'Phản hồi cập nhật dự án '.$update->project->name,
                'message' => $user->name.': '.str($data['response'])->limit(160),
                'data' => ['link' => route('academic-projects.show', $update->academic_project_id)],
                'is_read' => false,
            ]);
        }

        return back()->with('success', 'Đã gửi phản hồi.');
    }

    /** Báo cáo dự án học thuật (khu Báo cáo): tiến độ từng dự án, theo thành viên, khó khăn chưa phản hồi, biên bản trễ deadline. */
    public function report(Request $request): Response
    {
        $month = ReportPeriod::pick($request->input('month'), ReportPeriod::MONTH_PATTERN, ReportPeriod::currentMonth());
        [$from, $to] = ReportPeriod::monthRange($month);
        $to = $to->copy()->endOfDay();
        $status = $request->validate(['status' => ['nullable', Rule::in(array_keys(AcademicProject::STATUSES))]])['status'] ?? null;

        $projects = AcademicProject::query()
            ->with(['owner:id,name', 'milestones.assignee:id,name'])
            // Mặc định: dự án còn mở + dự án đóng từ đầu tháng đang xem.
            ->when($status, fn ($q) => $q->where('status', $status), fn ($q) => $q->where(fn ($w) => $w->whereIn('status', AcademicProject::OPEN_STATUSES)
                ->orWhere(fn ($c) => $c->whereIn('status', ['completed', 'cancelled'])->where('updated_at', '>=', $from))))
            ->orderByRaw("case status when 'active' then 0 when 'planning' then 1 when 'paused' then 2 else 3 end")->orderBy('deadline')
            ->get();
        $projectIds = $projects->pluck('id');
        $updates = AcademicProjectUpdate::query()->whereIn('academic_project_id', $projectIds)->with('user:id,name')->latest()->get();
        $lastUpdate = $updates->groupBy('academic_project_id')->map->first();
        $milestones = $projects->flatMap->milestones;
        $penalties = $this->penalties($milestones->pluck('id')->all());

        $rows = $projects->map(function (AcademicProject $p) use ($lastUpdate, $updates) {
            $last = $lastUpdate->get($p->id);

            return $this->summary($p) + [
                'last_update' => $last ? ['at' => $last->created_at->toIso8601String(), 'user' => $last->user?->name] : null,
                'open_issues' => $updates->where('academic_project_id', $p->id)->filter(fn ($u) => filled($u->difficulties) && blank($u->response))->count(),
            ];
        })->values();

        $byMember = $milestones->filter(fn (AcademicProjectMilestone $m) => $m->assignee_id)
            ->groupBy('assignee_id')
            ->map(function (Collection $items) use ($updates, $from, $to, $penalties) {
                $user = $items->first()->assignee;

                return [
                    'id' => $user?->id,
                    'name' => $user?->name ?? '—',
                    'assigned' => $items->count(),
                    'done_on_time' => $items->filter(fn ($m) => $m->isDone() && ! $m->isOverdue())->count(),
                    'done_late' => $items->filter(fn ($m) => $m->isDone() && $m->isOverdue())->count(),
                    'overdue' => $items->filter(fn ($m) => ! $m->isDone() && $m->isOverdue())->count(),
                    'in_progress' => $items->filter(fn ($m) => ! $m->isDone() && ! $m->isOverdue())->count(),
                    'updates_in_month' => $updates->where('user_id', $user?->id)->filter(fn ($u) => $u->created_at->between($from, $to))->count(),
                    'penalties' => $penalties->where('user_id', $user?->id)->count(),
                ];
            })->sortByDesc('overdue')->values();

        $issues = $updates->filter(fn ($u) => filled($u->difficulties))
            ->sortBy(fn ($u) => [$u->response ? 1 : 0, -$u->created_at->timestamp])->take(15)
            ->map(fn (AcademicProjectUpdate $u) => [
                'id' => $u->id,
                'project_id' => $u->academic_project_id,
                'project' => $projects->firstWhere('id', $u->academic_project_id)?->name,
                'user' => $u->user?->name,
                'created_at' => $u->created_at->toIso8601String(),
                'difficulties' => $u->difficulties,
                'responded' => filled($u->response),
            ])->values();

        $now = now();

        return Inertia::render('AcademicProjects/Report', [
            'month' => $month,
            'months' => ReportPeriod::monthOptions(),
            'statuses' => Ui::options(AcademicProject::STATUSES),
            'stats' => [
                'active' => $projects->where('status', 'active')->count(),
                'overdue' => $milestones->filter(fn ($m) => ! $m->isDone() && $m->isOverdue($now) && $m->project?->status === 'active')->count(),
                'done_in_month' => $milestones->filter(fn ($m) => $m->isDone() && $m->completed_at?->between($from, $to))->count(),
                'due_in_month' => $milestones->filter(fn ($m) => $m->due_date->between($from, $to))->count(),
                'penalties_in_month' => $penalties->filter(fn ($p) => $p['created_at'] && Carbon::parse($p['created_at'])->between($from, $to))->count(),
                'open_issues' => $rows->sum('open_issues'),
            ],
            'projects' => $rows,
            'members' => $byMember,
            'issues' => $issues,
            'penalties' => $penalties->filter(fn ($p) => $p['created_at'] && Carbon::parse($p['created_at'])->between($from, $to))->values(),
        ]);
    }

    // ───────────────────────── helpers ─────────────────────────

    /** Nhân sự chọn làm người phụ trách / thành viên / người nhận mốc: tài khoản đang hoạt động có quyền dự án học thuật. */
    private function staff(): Builder
    {
        return Rbac::scopeUsersWithPermission(User::query()->where('is_active', true), 'academic_project.view')->orderBy('name');
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'types' => Ui::options(AcademicProject::TYPES),
            'staff' => Ui::options($this->staff()->get(['id', 'name']), 'name'),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(AcademicProject $p): array
    {
        $milestones = $p->milestones;
        $now = now();

        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'type' => $p->type,
            'type_label' => AcademicProject::TYPES[$p->type] ?? $p->type,
            'status' => $p->status,
            'status_label' => AcademicProject::STATUSES[$p->status] ?? $p->status,
            'status_color' => AcademicProject::STATUS_COLORS[$p->status] ?? 'neutral',
            'owner' => $p->owner?->name,
            'start_date' => $p->start_date?->toDateString(),
            'deadline' => $p->deadline?->toDateString(),
            'progress' => $p->progressPercent(),
            'milestones_total' => $milestones->count(),
            'milestones_done' => $milestones->filter->isDone()->count(),
            'milestones_overdue' => $milestones->filter(fn ($m) => ! $m->isDone() && $m->isOverdue($now))->count(),
            'next_milestone' => ($next = $milestones->reject->isDone()->sortBy('due_date')->first())
                ? ['title' => $next->title, 'due_date' => $next->due_date->toDateString()] : null,
            'members_count' => $p->members_count ?? $p->members?->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function milestoneRow(AcademicProjectMilestone $m, User $user, bool $canManage): array
    {
        return [
            'id' => $m->id,
            'title' => $m->title,
            'description' => $m->description,
            'assignee_id' => $m->assignee_id,
            'assignee' => $m->assignee?->name,
            'start_date' => $m->start_date?->toDateString(),
            'due_date' => $m->due_date->toDateString(),
            'target_quantity' => $m->target_quantity,
            'done_quantity' => $m->done_quantity,
            'quantity_label' => AcademicProjectMilestone::formatQuantity($m->done_quantity).' / '.AcademicProjectMilestone::formatQuantity($m->target_quantity).' '.$m->unit,
            'unit' => $m->unit,
            'status' => $m->status,
            'status_label' => AcademicProjectMilestone::STATUSES[$m->status] ?? $m->status,
            'completed_at' => $m->completed_at?->toIso8601String(),
            'progress' => $m->progressPercent(),
            'deadline_state' => $m->deadlineState(),
            'mine' => $m->assignee_id === $user->id,
            'can_update' => $canManage || $m->assignee_id === $user->id,
        ];
    }

    /**
     * Biên bản trễ deadline của các mốc (sổ SLA → Penalty).
     *
     * @param  list<int>  $milestoneIds
     */
    private function penalties(array $milestoneIds): Collection
    {
        if ($milestoneIds === []) {
            return collect();
        }
        $events = SlaEvent::query()->where('rule_key', AcademicProjectMilestone::SLA_RULE)
            ->where('subject_type', AcademicProjectMilestone::SLA_SUBJECT)->whereIn('subject_id', $milestoneIds)
            ->whereNotNull('penalty_id')->get()->keyBy('penalty_id');
        $titles = AcademicProjectMilestone::withTrashed()->whereIn('id', $events->pluck('subject_id'))->pluck('title', 'id');

        return Penalty::query()->whereIn('id', $events->keys())->with('user:id,name')->latest()->get()
            ->map(fn (Penalty $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'user_id' => $p->user_id,
                'user' => $p->user?->name,
                'milestone' => $titles[$events[$p->id]->subject_id] ?? null,
                'status' => $p->status,
                'status_label' => $p->status_label,
                'amount' => (float) $p->amount,
                'created_at' => $p->created_at?->toIso8601String(),
                'url' => route('penalties.index', ['search' => $p->code]),
            ]);
    }

    /** @return array<string, mixed> */
    private function validatedProject(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(AcademicProject::TYPES))],
            'owner_id' => ['required', 'integer'],
            'member_ids' => ['nullable', 'array', 'max:50'],
            'member_ids.*' => ['integer'],
            'start_date' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'kickoff_notes' => ['nullable', 'string', 'max:10000'],
            'kickoff_link' => ['nullable', 'url', 'max:500'],
        ], [
            'name.required' => 'Nhập tên dự án.',
            'owner_id.required' => 'Chọn người phụ trách.',
            'deadline.after_or_equal' => 'Deadline phải sau ngày bắt đầu.',
            'kickoff_link.url' => 'Link biên bản họp phải là đường dẫn đầy đủ (https://…).',
        ]);
        $ids = array_unique(array_map('intval', [$data['owner_id'], ...($data['member_ids'] ?? [])]));
        if ($this->staff()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['member_ids' => 'Có người được chọn không còn hoạt động hoặc không có quyền dự án học thuật.']);
        }

        return $data;
    }

    /** Thành viên = danh sách chọn + người phụ trách. @return list<int> */
    private function memberIds(array $data): array
    {
        return array_values(array_unique(array_map('intval', [$data['owner_id'], ...($data['member_ids'] ?? [])])));
    }

    /** @return array<string, mixed> */
    private function validatedMilestone(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'assignee_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:start_date'],
            'target_quantity' => ['required', 'numeric', 'min:0.01', 'max:99999'],
            'unit' => ['required', 'string', 'max:30'],
            'status' => ['nullable', Rule::in(array_keys(AcademicProjectMilestone::STATUSES))],
        ], [
            'title.required' => 'Nhập tên mốc.',
            'due_date.required' => 'Chọn deadline của mốc.',
            'due_date.after_or_equal' => 'Deadline phải sau ngày bắt đầu.',
            'target_quantity.required' => 'Nhập khối lượng cần làm (vd 12 unit).',
            'target_quantity.min' => 'Khối lượng phải lớn hơn 0.',
            'unit.required' => 'Nhập đơn vị khối lượng (unit, trang, bài…).',
        ]);
        if (! empty($data['assignee_id']) && ! $this->staff()->whereKey($data['assignee_id'])->exists()) {
            throw ValidationException::withMessages(['assignee_id' => 'Người nhận không còn hoạt động hoặc không có quyền dự án học thuật.']);
        }
        $data['assignee_id'] = $data['assignee_id'] ?? null;
        $data['status'] = $data['status'] ?? 'todo';

        return $data;
    }

    /** Người nhận mốc tự thành thành viên dự án; báo khi được giao mốc mới. */
    private function afterAssign(AcademicProject $project, AcademicProjectMilestone $milestone, ?int $previous, User $actor): void
    {
        if (! $milestone->assignee_id || $milestone->assignee_id === $previous) {
            return;
        }
        $project->members()->syncWithoutDetaching([$milestone->assignee_id]);
        $this->notifyUsers([$milestone->assignee_id], $actor, 'academic_project_milestone',
            "Bạn được giao mốc \"{$milestone->title}\"",
            "Dự án {$project->name}: deadline {$milestone->due_date->format('d/m/Y')}, khối lượng ".AcademicProjectMilestone::formatQuantity($milestone->target_quantity)." {$milestone->unit}.",
            $project);
    }

    /** @return list<int> */
    private function participantIds(AcademicProject $project): array
    {
        return collect([$project->owner_id])->merge($project->members->pluck('id'))->merge($project->milestones->pluck('assignee_id'))
            ->filter()->unique()->values()->all();
    }

    /** @param  iterable<int>  $userIds */
    private function notifyUsers(iterable $userIds, User $actor, string $type, string $title, string $message, AcademicProject $project): void
    {
        foreach (collect($userIds)->unique()->reject(fn ($id) => (int) $id === $actor->id) as $userId) {
            AdminNotification::create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => ['link' => route('academic-projects.show', $project->id)],
                'is_read' => false,
            ]);
        }
    }

    /** Link sản phẩm: mỗi dòng một link, tối đa 5, phải là http(s). @return list<string> */
    private function parseLinks(?string $text): array
    {
        $links = collect(preg_split('/\s*[\r\n]+\s*/', trim((string) $text)))->filter()->unique()->values();
        if ($links->count() > AcademicProjectUpdate::MAX_LINKS) {
            throw ValidationException::withMessages(['links_text' => 'Tối đa '.AcademicProjectUpdate::MAX_LINKS.' link mỗi lần cập nhật.']);
        }
        foreach ($links as $link) {
            if (! filter_var($link, FILTER_VALIDATE_URL) || ! preg_match('~^https?://~i', $link) || strlen($link) > 500) {
                throw ValidationException::withMessages(['links_text' => "Link không hợp lệ: {$link}. Dán đường dẫn đầy đủ (https://…), mỗi dòng một link."]);
            }
        }

        return $links->all();
    }
}
