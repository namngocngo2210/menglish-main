{{-- Xác nhận hoàn thành thủ công (mockup phan-cong-cong-viec/x_c_nh_n_ho_n_th_nh_th_c_ng).
     Gồm việc "Chờ xác nhận" không ảnh minh chứng (người giao việc xác nhận) và báo cáo trực lớp
     không ảnh (A6 Q8: GV chính của lớp; lớp chưa có GV chính → người giao việc). --}}
@php
    $items = collect($pendingReports)->map(fn ($r) => ['kind' => 'report', 'model' => $r, 'sort' => $r->created_at])
        ->merge(collect($pendingTasks)->map(fn ($t) => ['kind' => 'task', 'model' => $t, 'sort' => $t->updated_at]))
        ->sortByDesc('sort')->values();
    $filterQuery = array_filter(['kind' => $kind, 'q' => $search ?: null, 'assignee_id' => $assigneeFilter]);
@endphp
<x-app-layout title="Xác nhận hoàn thành thủ công">
    <x-ui.page-header title="Xác nhận hoàn thành thủ công" description="Danh sách các đầu việc chờ xác nhận từ Trợ giảng: báo cáo không đính kèm ảnh minh chứng cần người giao việc (báo cáo trực lớp: GV chính của lớp) xác nhận." />

    @if (session('info'))
        <x-ui.alert type="warning" class="mb-md" dismissible>{{ session('info') }}</x-ui.alert>
    @endif

    <x-ui.filter-bar :action="route('tasks.manual-approvals')" search="q" placeholder="Đầu việc, lớp, trợ giảng...">
        <x-ui.select name="kind" label="Loại" :options="['task' => 'Đầu việc', 'report' => 'Báo cáo trực lớp']" :value="$kind" placeholder="Tất cả loại" />
        <x-ui.select name="assignee_id" label="Người thực hiện" :options="$assigneeOptions" :value="$assigneeFilter" placeholder="Tất cả người thực hiện" />
    </x-ui.filter-bar>

    {{-- Danh sách chờ xác nhận: bấm dòng → chi tiết mở trong modal (?selected_id= / ?report=; đóng modal thì bỏ query). --}}
    <x-ui.data-table>
        <table>
            <thead>
                <tr>
                    <th>Đầu việc</th>
                    <th>Trợ giảng</th>
                    <th>Ngày giao</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    @php
                        $m = $item['model'];
                        $isReport = $item['kind'] === 'report';
                        $selected = $isReport ? ($selectedReport && $selectedReport->id === $m->id) : ($selectedTask && $selectedTask->id === $m->id);
                        $url = route('tasks.manual-approvals', $filterQuery + ($isReport ? ['report' => $m->id] : ['selected_id' => $m->id]));
                        $person = $isReport ? $m->reporter : $m->assignee;
                    @endphp
                    <tr data-href="{{ $url }}" @class(['cursor-pointer', 'bg-primary-fixed/40' => $selected]) data-item="{{ $item['kind'] }}-{{ $m->id }}">
                        <td>
                            <div class="flex flex-col gap-xs">
                                <span class="flex flex-wrap items-center gap-xs">
                                    <x-ui.badge color="status-pending">Chờ xác nhận</x-ui.badge>
                                    @if ($isReport)
                                        <x-ui.badge color="info" :dot="false">Báo cáo trực lớp</x-ui.badge>
                                    @endif
                                </span>
                                <a href="{{ $url }}" class="font-semibold text-on-surface hover:text-primary">{{ $isReport ? $m->session_name : $m->title }}</a>
                                <span class="flex items-center gap-xs font-caption text-caption text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">{{ ($isReport || $m->classModel) ? 'class' : 'work' }}</span>
                                    {{ $isReport ? ($m->classModel?->name ?? 'Lớp đã xóa') : ($m->classModel?->name ?? 'Công việc chung') }}
                                </span>
                            </div>
                        </td>
                        <td>
                            <div class="flex items-center gap-sm">
                                <x-ui.avatar :name="$person?->name ?? '—'" size="sm" />
                                <span class="font-body-small text-body-small">{{ $person?->name ?? 'Chưa phân công' }}</span>
                            </div>
                        </td>
                        <td class="whitespace-nowrap font-code text-body-small">
                            <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">calendar_today</span>{{ ($isReport ? $m->session_date : ($m->created_at ?? $m->due_date))?->format('d/m/Y') }}</span>
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-xs">
                                <x-ui.button size="sm" variant="ghost" icon="visibility" :href="$url">Xem</x-ui.button>
                                <form method="POST" action="{{ $isReport ? route('tasks.class-reports.approve', $m->id) : route('tasks.approve', $m->id) }}">
                                    @csrf
                                    <x-ui.button type="submit" size="sm" variant="secondary">Xác nhận</x-ui.button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <x-ui.empty-state icon="task_alt" title="Không có đầu việc nào cần xác nhận thủ công"
                                description="Các báo cáo đã được xử lý hoặc đã hoàn thành tự động (có ảnh minh chứng)." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.data-table>

    @if ($selectedReport || $selectedTask)
        @php
            $isReport = (bool) $selectedReport;
            $m = $selectedReport ?? $selectedTask;
            $taskOfItem = $isReport ? $m->task : $m;
            $person = $isReport ? $m->reporter : $m->assignee;
            $note = $isReport ? null : $m->completion_note;
            preg_match_all('~https?://[^\s<>"\']+~u', (string) ($isReport ? $m->topics_learned.' '.$m->teaching_log : $note), $noteLinks);
            $approveAction = $isReport ? route('tasks.class-reports.approve', $m->id) : route('tasks.approve', $m->id);
            $rejectAction = $isReport ? route('tasks.class-reports.reject', $m->id) : route('tasks.reject', $m->id);
            $rejectField = $isReport ? 'reason' : 'admin_note';
        @endphp

        {{-- Chi tiết + xác nhận: mở sẵn khi URL chọn 1 mục; đóng → bỏ selected_id / report khỏi thanh địa chỉ. --}}
        <x-ui.modal name="manual-approval-detail" :title="$isReport ? $m->session_name : $m->title" max-width="2xl" show
                    :dismiss-url="route('tasks.manual-approvals', $filterQuery)"
                    x-data="{ openReject: {{ $errors->has($rejectField) ? 'true' : 'false' }} }">
            <div class="space-y-md font-body-small text-body-small" data-detail="{{ $isReport ? 'report' : 'task' }}-{{ $m->id }}">
                <div class="flex flex-wrap items-center justify-between gap-sm">
                    <span class="flex flex-wrap items-center gap-xs">
                        <x-ui.badge color="status-pending">Chờ xác nhận</x-ui.badge>
                        @if ($isReport)
                            <x-ui.badge color="info" :dot="false">Báo cáo trực lớp</x-ui.badge>
                        @endif
                    </span>
                    <span class="font-caption text-caption text-on-surface-variant">Cập nhật: {{ $m->updated_at?->diffForHumans() }}</span>
                </div>
                <p class="flex items-center gap-xs text-secondary">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">class</span>
                    {{ $m->classModel?->name ?? 'Công việc chung' }}{{ ! $isReport && $m->lesson_session ? ' · '.$m->lesson_session : '' }}
                </p>

                <div class="space-y-xs rounded-lg border border-outline-variant bg-surface-container-low p-sm">
                    <p class="font-label text-label uppercase text-on-surface-variant">Thông tin giao việc</p>
                    <p class="flex justify-between gap-sm"><span class="text-on-surface-variant">Người thực hiện:</span><span class="font-semibold">{{ $person?->name ?? '—' }}</span></p>
                    <p class="flex justify-between gap-sm"><span class="text-on-surface-variant">Người giao việc:</span><span>{{ $taskOfItem?->creator?->name ?? '—' }}</span></p>
                    <p class="flex justify-between gap-sm"><span class="text-on-surface-variant">Ngày giao:</span><span class="font-code">{{ ($taskOfItem?->created_at ?? $m->created_at)?->format('d/m/Y') }}</span></p>
                    <p class="flex justify-between gap-sm"><span class="text-on-surface-variant">Hạn chót:</span>
                        <span class="font-code font-semibold text-error">{{ $taskOfItem?->due_date ? $taskOfItem->due_date->format('d/m/Y').' '.substr((string) ($taskOfItem->due_time ?: '23:59'), 0, 5) : '—' }}</span></p>
                    @if ($isReport)
                        <p class="flex justify-between gap-sm"><span class="text-on-surface-variant">Người xác nhận:</span><span class="font-semibold">{{ $m->confirmerRoleLabel() }}</span></p>
                    @endif
                </div>

                <div class="space-y-sm">
                    <p class="flex items-center gap-xs font-label text-label uppercase text-on-surface-variant">
                        <span class="material-symbols-outlined text-[16px] text-warning" aria-hidden="true">warning</span>
                        {{ $isReport ? 'Báo cáo trực lớp' : 'Báo cáo từ Trợ giảng' }}
                    </p>
                    <div class="space-y-sm rounded-lg border border-primary-fixed bg-primary-light p-sm text-on-surface">
                        @if ($isReport)
                            <p><span class="font-semibold">Hôm nay học gì:</span> {{ $m->topics_learned }}</p>
                            @if ($m->teaching_log)
                                <p><span class="font-semibold">Nhật ký dạy:</span> {{ $m->teaching_log }}</p>
                            @endif
                            @if ($m->studentSupports->isNotEmpty())
                                <div>
                                    <p class="font-semibold">Học sinh cần bổ trợ ({{ $m->studentSupports->count() }}):</p>
                                    <ul class="ml-md list-disc">
                                        @foreach ($m->studentSupports as $support)
                                            <li>{{ $support->student?->name ?? 'Học sinh' }} — {{ $support->reason }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @else
                            <p class="whitespace-pre-line">{{ $note ?: 'Trợ giảng không ghi chú khi báo hoàn thành.' }}</p>
                        @endif
                        @foreach (array_unique($noteLinks[0] ?? []) as $noteLink)
                            <a href="{{ $noteLink }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-xs truncate rounded border border-primary-fixed bg-surface-container-lowest p-xs text-secondary hover:underline">
                                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">link</span>{{ $noteLink }}
                            </a>
                        @endforeach
                        <p class="flex items-start gap-xs rounded border border-error/20 bg-error-container/40 p-xs font-caption text-caption text-error">
                            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">info</span>
                            <span><strong>Ghi chú:</strong> TA báo cáo hoàn thành nhưng không đính kèm ảnh chụp minh chứng lên hệ thống.</span>
                        </p>
                    </div>
                </div>

                <form id="approvalForm" method="POST" action="{{ $approveAction }}" class="space-y-xs" x-show="! openReject">
                    @csrf
                    <x-ui.textarea name="admin_note" label="Ghi chú xác nhận (Tùy chọn)" rows="2" placeholder="Nhập ghi chú hoặc phản hồi cho TA..." />
                </form>
                <form id="rejectForm" x-show="openReject" x-cloak method="POST" action="{{ $rejectAction }}" class="space-y-xs rounded-lg border border-error/30 bg-error-container/30 p-sm">
                    @csrf
                    <x-ui.textarea :name="$rejectField" id="reject-note" label="Lý do từ chối / yêu cầu làm lại" rows="2" required placeholder="Ví dụ: thiếu ảnh lớp, thông tin chưa chính xác..." />
                </form>
            </div>

            <x-slot:footer>
                <div x-show="! openReject" class="flex flex-wrap justify-end gap-sm">
                    <x-ui.button variant="danger-text" icon="undo" x-on:click="openReject = true; $nextTick(() => document.getElementById('reject-note')?.focus())">Từ chối / Yêu cầu bổ sung</x-ui.button>
                    <x-ui.button type="submit" form="approvalForm" icon="check_circle">Xác nhận hoàn thành</x-ui.button>
                </div>
                <div x-show="openReject" x-cloak class="flex flex-wrap justify-end gap-sm">
                    <x-ui.button variant="secondary" x-on:click="openReject = false">Quay lại</x-ui.button>
                    <x-ui.button type="submit" form="rejectForm" variant="danger" icon="send">Gửi yêu cầu làm lại</x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</x-app-layout>
