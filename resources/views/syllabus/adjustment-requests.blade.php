<x-app-layout>
    <x-ui.page-header title="Duyệt yêu cầu xin điều chỉnh tiến độ" description="Quản lý các yêu cầu giãn tiến độ từ giáo viên. Duyệt sẽ thêm buổi vào cuối lịch của lớp; từ chối bắt buộc nhập lý do." :back="route('syllabus.documents')">
        <x-slot:actions>
            <form method="GET" class="flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px] text-on-surface-variant">filter_list</span>
                <x-ui.select name="status" :value="$status" :options="['all' => 'Tất cả'] + \App\Models\SyllabusAdjustmentRequest::STATUS_LABELS" onchange="this.form.submit()" aria-label="Lọc" />
            </form>
            <x-ui.button variant="secondary" icon="speed" :href="route('syllabus.teacher-adjust')">Gửi yêu cầu mới</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>


    @php($canReview = auth()->user()->can('syllabus.approve_adjustment'))
    @php($listTitle = ['pending' => 'Danh sách chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Đã từ chối'][$status] ?? 'Tất cả yêu cầu')
    @php($listQuery = array_filter(['status' => $status, 'page' => request('page')]))

    {{-- Mockup 01_Web_Admin/04: danh sách yêu cầu (SLA) — bấm dòng → chi tiết trong modal (?request=; đóng modal thì bỏ query). --}}
    <x-ui.data-table min-width="760px">
        <x-slot:header>
            <h2 class="font-h3 text-h3 text-on-surface">{{ $listTitle }}</h2>
            <x-ui.badge color="primary" :dot="false" pill>{{ $requests->total() }} yêu cầu</x-ui.badge>
        </x-slot:header>
        <table>
            <thead>
                <tr>
                    <th>Giáo viên</th>
                    <th>Lớp / Chặng học</th>
                    <th class="text-right">Xin thêm</th>
                    <th>Lý do</th>
                    <th>Ngày gửi</th>
                    <th>Trạng thái</th>
                    <th class="text-right"><span class="sr-only">Thao tác</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($requests as $req)
                    @php($detailUrl = route('syllabus.adjustment-requests', $listQuery + ['request' => $req->id]))
                    <tr data-href="{{ $detailUrl }}" @class(['cursor-pointer', 'bg-primary-fixed/30' => $selected?->id === $req->id])>
                        <td>
                            <a href="{{ $detailUrl }}" class="flex items-center gap-sm font-semibold text-on-surface hover:text-primary">
                                <x-ui.avatar :name="$req->teacher?->name ?? '?'" size="sm" />
                                <span class="truncate">{{ $req->teacher?->name ?? '—' }}</span>
                            </a>
                        </td>
                        <td>{{ $req->class_stage_label }}</td>
                        <td class="whitespace-nowrap text-right font-semibold">{{ $req->extra_sessions ?: 0 }} buổi</td>
                        <td class="max-w-[280px]"><p class="line-clamp-1 text-on-surface-variant" title="{{ $req->reason }}">{{ $req->reason }}</p></td>
                        <td class="whitespace-nowrap font-code text-body-small">{{ $req->created_at->format('d/m/Y') }}</td>
                        <td class="whitespace-nowrap">
                            @if ($req->status === 'pending')
                                <x-ui.badge :color="$req->isSlaOverdue() ? 'error' : 'success'">{{ $req->isSlaOverdue() ? 'Quá hạn' : 'Còn hạn' }}</x-ui.badge>
                            @else
                                <x-ui.badge :color="$req->status === 'approved' ? 'success' : 'error'">{{ $req->status_label }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="text-right"><x-ui.button variant="secondary" size="sm" icon="visibility" :href="$detailUrl">Xem</x-ui.button></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-ui.empty-state icon="inbox" title="Không có yêu cầu nào" /></td></tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer><x-ui.pagination :paginator="$requests" :options="[]" unit="yêu cầu" /></x-slot:footer>
    </x-ui.data-table>

    @if ($selected)
        {{-- Chi tiết yêu cầu: mở sẵn khi URL có ?request=; đóng → bỏ request khỏi thanh địa chỉ. --}}
        <x-ui.modal name="adjustment-detail" title="Chi tiết yêu cầu điều chỉnh tiến độ" max-width="2xl" show
                    :dismiss-url="route('syllabus.adjustment-requests', $listQuery)"
                    x-data="{ rejecting: {{ $errors->has('rejection_reason') ? 'true' : 'false' }} }">
            <div class="space-y-lg">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-sm">
                        <x-ui.avatar :name="$selected->teacher?->name ?? '?'" />
                        <div>
                            <p class="font-h3 text-h3 text-on-surface">{{ $selected->teacher?->name ?? '—' }}</p>
                            <p class="font-caption text-caption text-on-surface-variant">{{ $selected->teacher?->employee_code ?: 'Giáo viên' }}</p>
                        </div>
                    </div>
                    @if ($selected->status === 'pending')
                        <x-ui.badge :color="$selected->isSlaOverdue() ? 'error' : 'success'">{{ $selected->isSlaOverdue() ? 'Quá hạn xử lý' : 'Còn hạn xử lý' }}</x-ui.badge>
                    @else
                        <x-ui.badge :color="$selected->status === 'approved' ? 'success' : 'error'">{{ $selected->status_label }}</x-ui.badge>
                    @endif
                </div>

                <dl class="grid grid-cols-1 gap-md rounded-lg border border-outline-variant bg-surface-container-low p-md sm:grid-cols-3">
                    <div class="sm:col-span-3">
                        <dt class="font-label text-label uppercase text-on-surface-variant">Lớp / Chặng học</dt>
                        <dd class="mt-xs flex items-center gap-xs font-body-medium text-body-medium text-on-surface"><span class="material-symbols-outlined text-[18px] text-primary">school</span>{{ $selected->class_stage_label }}</dd>
                    </div>
                    <div>
                        <dt class="font-label text-label uppercase text-on-surface-variant">Ngày gửi yêu cầu</dt>
                        <dd class="mt-xs flex items-center gap-xs font-body-medium text-body-small text-on-surface"><span class="material-symbols-outlined text-[16px]">calendar_today</span>{{ $selected->created_at->format('d/m/Y') }}</dd>
                    </div>
                    <div>
                        <dt class="font-label text-label uppercase text-on-surface-variant">Số buổi xin thêm</dt>
                        <dd class="mt-xs flex items-center gap-xs font-body-medium text-body-small text-primary"><span class="material-symbols-outlined text-[16px]">add_circle</span>+{{ $selected->extra_sessions ?: 0 }} buổi</dd>
                    </div>
                    <div>
                        <dt class="font-label text-label uppercase text-on-surface-variant">Kết thúc lớp hiện tại</dt>
                        <dd class="mt-xs font-code text-body-small text-on-surface">{{ $selected->classModel?->end_date?->format('d/m/Y') ?? '—' }}</dd>
                    </div>
                </dl>

                <div>
                    <p class="mb-xs font-label text-label uppercase text-on-surface-variant">Lý do xin giãn tiến độ</p>
                    <div class="relative rounded-lg border border-outline-variant bg-surface-container-lowest p-md pl-xl">
                        <span class="material-symbols-outlined absolute left-sm top-sm text-[20px] text-outline">format_quote</span>
                        <p class="whitespace-pre-line font-body-base text-body-base text-on-surface">{{ $selected->reason }}</p>
                        @if ($selected->request_type)<p class="mt-xs font-caption text-caption text-on-surface-variant">{{ $selected->request_type }}</p>@endif
                    </div>
                </div>

                @if ($selected->status === 'approved')
                    <x-ui.alert type="success" class="font-body-small text-body-small">
                        <p class="font-semibold">Đã duyệt bởi {{ $selected->approver?->name }} {{ $selected->reviewed_at ? 'lúc '.$selected->reviewed_at->format('H:i d/m/Y') : '' }}</p>
                        <p class="mt-1">{{ $selected->applied_note }}</p>
                    </x-ui.alert>
                @elseif ($selected->status === 'rejected')
                    <x-ui.alert type="error" class="font-body-small text-body-small">
                        <p class="font-semibold">Đã từ chối bởi {{ $selected->approver?->name }} {{ $selected->reviewed_at ? 'lúc '.$selected->reviewed_at->format('H:i d/m/Y') : '' }}</p>
                        <p class="mt-1">Lý do: {{ $selected->rejection_reason ?: '—' }}</p>
                    </x-ui.alert>
                @elseif ($canReview)
                    <form id="reject-form" method="POST" action="{{ route('syllabus.adjustment-requests.reject', $selected->id) }}" x-show="rejecting" x-cloak class="rounded-lg border border-error/30 bg-error/5 p-md">
                        @csrf
                        <x-ui.textarea label="Lý do từ chối (Bắt buộc)" name="rejection_reason" id="reject-reason" rows="3" placeholder="Nhập lý do chi tiết để phản hồi lại giáo viên..." />
                    </form>
                    <form id="approve-form" method="POST" action="{{ route('syllabus.adjustment-requests.approve', $selected->id) }}" x-show="! rejecting">
                        @csrf
                        <x-ui.input type="number" name="extra_sessions" label="Số buổi thêm vào lịch khi duyệt" min="0" :max="\App\Models\SyllabusAdjustmentRequest::MAX_EXTRA_SESSIONS" :value="$selected->extra_sessions ?? 0"
                                    hint="Buổi mới nối tiếp sau buổi cuối của lớp, theo TKB, bỏ qua ngày nghỉ lễ; 0 = chỉ ghi nhận, không đổi lịch." />
                    </form>
                @endif
            </div>

            @if ($canReview && $selected->status === 'pending')
                <x-slot:footer>
                    <div x-show="! rejecting" class="flex flex-wrap justify-end gap-sm">
                        <x-ui.button variant="danger-text" icon="cancel" @click="rejecting = true; $nextTick(() => document.getElementById('reject-reason')?.focus())">Từ chối</x-ui.button>
                        <x-ui.button type="submit" form="approve-form" icon="check_circle">Phê duyệt</x-ui.button>
                    </div>
                    <div x-show="rejecting" x-cloak class="flex flex-wrap justify-end gap-sm">
                        <x-ui.button variant="secondary" @click="rejecting = false">Quay lại</x-ui.button>
                        <x-ui.button type="submit" form="reject-form" variant="danger">Xác nhận từ chối</x-ui.button>
                    </div>
                </x-slot:footer>
            @endif
        </x-ui.modal>
    @endif
</x-app-layout>
