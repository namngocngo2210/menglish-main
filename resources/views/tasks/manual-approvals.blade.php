{{-- Xác nhận hoàn thành thủ công (mockup phan-cong-cong-viec/x_c_nh_n_ho_n_th_nh_th_c_ng).
     Gồm việc "Chờ xác nhận" không ảnh minh chứng (người giao việc xác nhận) và báo cáo trực lớp
     không ảnh (A6 Q8: GV chính của lớp; lớp chưa có GV chính → người giao việc). --}}
@php
    $items = collect($pendingReports)->map(fn ($r) => ['kind' => 'report', 'model' => $r, 'sort' => $r->created_at])
        ->merge(collect($pendingTasks)->map(fn ($t) => ['kind' => 'task', 'model' => $t, 'sort' => $t->updated_at]))
        ->sortByDesc('sort')->values();
    $filterQuery = array_filter(['kind' => $kind, 'q' => $search ?: null, 'assignee_id' => $assigneeFilter]);
    $isFiltered = ! empty($filterQuery);
@endphp
<x-app-layout title="Xác nhận hoàn thành thủ công">
    <x-ui.page-header title="Xác nhận hoàn thành thủ công" description="Danh sách các đầu việc chờ xác nhận từ Trợ giảng: báo cáo không đính kèm ảnh minh chứng cần người giao việc (báo cáo trực lớp: GV chính của lớp) xác nhận.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="filter_list" x-data x-on:click="document.getElementById('approval-filters').classList.toggle('hidden')">Bộ lọc</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('info'))
        <x-ui.alert type="warning" class="mb-md" dismissible>{{ session('info') }}</x-ui.alert>
    @endif

    <form id="approval-filters" method="GET" action="{{ route('tasks.manual-approvals') }}"
          class="{{ $isFiltered ? '' : 'hidden' }} mb-md flex flex-wrap items-end gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
        <x-ui.input name="q" inline-label="Tìm:" :value="$search" placeholder="Đầu việc, lớp, trợ giảng..." />
        <x-ui.select name="kind" inline-label="Loại:" :options="['task' => 'Đầu việc', 'report' => 'Báo cáo trực lớp']" :value="$kind" placeholder="Tất cả" />
        <x-ui.select name="assignee_id" inline-label="Người thực hiện:" :options="$assigneeOptions" :value="$assigneeFilter" placeholder="Tất cả" />
        <x-ui.button type="submit" size="sm" icon="search">Lọc</x-ui.button>
        @if ($isFiltered)
            <x-ui.button size="sm" variant="ghost" icon="close" :href="route('tasks.manual-approvals')">Xóa lọc</x-ui.button>
        @endif
    </form>

    <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-3">
        {{-- Danh sách chờ xác nhận --}}
        <x-ui.data-table class="lg:col-span-2">
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
                        <tr class="cursor-pointer {{ $selected ? 'bg-primary-fixed/40' : '' }}" onclick="window.location.href='{{ $url }}'" data-item="{{ $item['kind'] }}-{{ $m->id }}">
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
                            <td class="text-right" onclick="event.stopPropagation()">
                                <form method="POST" action="{{ $isReport ? route('tasks.class-reports.approve', $m->id) : route('tasks.approve', $m->id) }}">
                                    @csrf
                                    <x-ui.button type="submit" size="sm">Xác nhận</x-ui.button>
                                </form>
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

        {{-- Chi tiết + xác nhận --}}
        <section class="lg:sticky lg:top-md">
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
                <div class="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest" data-detail="{{ $isReport ? 'report' : 'task' }}-{{ $m->id }}">
                    <div class="space-y-xs border-b border-surface-container bg-surface-container-low p-md">
                        <div class="flex items-center justify-between gap-sm">
                            <x-ui.badge color="status-pending">Chờ xác nhận</x-ui.badge>
                            <span class="font-caption text-caption text-on-surface-variant">Cập nhật: {{ $m->updated_at?->diffForHumans() }}</span>
                        </div>
                        <h2 class="font-h3 text-h3 text-on-surface">{{ $isReport ? $m->session_name : $m->title }}</h2>
                        <p class="flex items-center gap-xs font-body-small text-body-small text-secondary">
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">class</span>
                            {{ $m->classModel?->name ?? 'Công việc chung' }}{{ ! $isReport && $m->lesson_session ? ' · '.$m->lesson_session : '' }}
                        </p>
                    </div>

                    <div class="space-y-md p-md font-body-small text-body-small">
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

                        <form id="approvalForm" method="POST" action="{{ $approveAction }}" class="space-y-xs">
                            @csrf
                            <x-ui.textarea name="admin_note" label="Ghi chú xác nhận (Tùy chọn)" rows="2" placeholder="Nhập ghi chú hoặc phản hồi cho TA..." />
                        </form>
                    </div>

                    <div class="flex flex-col gap-sm border-t border-surface-container bg-surface-container-low p-md" x-data="{ openReject: {{ $errors->has($rejectField) ? 'true' : 'false' }} }">
                        <x-ui.button type="submit" form="approvalForm" icon="check_circle" class="w-full">Xác nhận hoàn thành</x-ui.button>
                        <x-ui.button variant="secondary" class="w-full" x-on:click="openReject = !openReject">Từ chối / Yêu cầu bổ sung</x-ui.button>
                        <form x-show="openReject" x-cloak method="POST" action="{{ $rejectAction }}" class="space-y-xs rounded-lg border border-error/30 bg-error-container/30 p-sm">
                            @csrf
                            <x-ui.textarea :name="$rejectField" label="Lý do từ chối / yêu cầu làm lại" rows="2" required placeholder="Ví dụ: thiếu ảnh lớp, thông tin chưa chính xác..." />
                            <div class="flex justify-end gap-xs">
                                <x-ui.button size="sm" variant="ghost" x-on:click="openReject = false">Hủy</x-ui.button>
                                <x-ui.button type="submit" size="sm" variant="danger" icon="send">Gửi yêu cầu làm lại</x-ui.button>
                            </div>
                        </form>
                    </div>
                </div>
            @else
                <div class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg text-center">
                    <span class="material-symbols-outlined text-[40px] text-outline" aria-hidden="true">touch_app</span>
                    <p class="font-body-small text-body-small text-on-surface-variant">Chọn một đầu việc bên trái để xem chi tiết và xác nhận.</p>
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
