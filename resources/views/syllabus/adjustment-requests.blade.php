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

    {{-- Mockup 01_Web_Admin/04: master (thẻ yêu cầu có SLA) – detail (lớp/chặng, ngày gửi, số buổi, lý do, Từ chối → xác nhận / Phê duyệt). --}}
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div class="lg:col-span-5 min-w-0 bg-surface-container-lowest rounded-xl border border-outline-variant shadow-sm overflow-hidden flex flex-col">
            <div class="px-lg py-md border-b border-outline-variant bg-surface-container-low flex items-center justify-between">
                <span class="font-h3 text-h3 text-on-surface">{{ $listTitle }}</span>
                <span class="rounded-full bg-primary-fixed px-sm py-0.5 font-label text-label text-primary">{{ $requests->total() }} Yêu cầu</span>
            </div>
            <div class="flex-1 space-y-sm overflow-y-auto p-md max-h-[720px]">
                @forelse ($requests as $req)
                    @php($active = $selected?->id === $req->id)
                    <a href="{{ route('syllabus.adjustment-requests', array_filter(['request' => $req->id, 'status' => $status, 'page' => request('page')])) }}"
                       class="relative block overflow-hidden rounded-lg border p-md transition-colors {{ $active ? 'border-primary-container bg-surface-container-low' : 'border-outline-variant bg-surface-container-lowest hover:bg-surface-container-low' }}">
                        <span class="absolute left-0 top-0 bottom-0 w-1 {{ $active ? 'bg-primary-container' : 'bg-transparent' }}"></span>
                        <div class="flex items-start justify-between gap-sm">
                            <div class="flex items-center gap-sm min-w-0">
                                <x-ui.avatar :name="$req->teacher?->name ?? '?'" size="sm" />
                                <div class="min-w-0">
                                    <h3 class="font-body-medium text-body-medium font-semibold text-on-surface truncate">{{ $req->teacher?->name ?? '—' }}</h3>
                                    <p class="font-caption text-caption text-on-surface-variant">Giáo viên</p>
                                </div>
                            </div>
                            @if ($req->status === 'pending')
                                <x-ui.badge :color="$req->isSlaOverdue() ? 'error' : 'success'">{{ $req->isSlaOverdue() ? 'Quá hạn' : 'Còn hạn' }}</x-ui.badge>
                            @else
                                <x-ui.badge :color="$req->status === 'approved' ? 'success' : 'error'">{{ $req->status_label }}</x-ui.badge>
                            @endif
                        </div>
                        <div class="mt-sm grid grid-cols-1 gap-xs font-body-small text-body-small text-on-surface-variant">
                            <span class="flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]">school</span>{{ $req->class_stage_label }}</span>
                            <span class="flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]">calendar_today</span>Gửi: {{ $req->created_at->format('d/m/Y') }}</span>
                            <span class="flex items-center gap-xs"><span class="material-symbols-outlined text-[16px] {{ $active ? 'text-primary' : '' }}">add_circle</span>Xin thêm: <strong class="{{ $active ? 'text-primary' : 'text-on-surface' }}">{{ $req->extra_sessions ?: 0 }}</strong> buổi</span>
                        </div>
                        <p class="mt-xs line-clamp-1 font-caption text-caption text-on-surface-variant">{{ $req->reason }}</p>
                    </a>
                @empty
                    <x-ui.empty-state icon="inbox" title="Không có yêu cầu nào" />
                @endforelse
            </div>
            <div class="border-t border-outline-variant"><x-ui.pagination :paginator="$requests" :options="[]" unit="yêu cầu" /></div>
        </div>

        <div class="lg:col-span-7 min-w-0">
            <div class="bg-surface-container-lowest rounded-xl border border-outline-variant shadow-sm overflow-hidden" x-data="{ rejecting: @js($errors->has('rejection_reason')) }">
                <div class="px-lg py-md border-b border-outline-variant flex items-center gap-2 bg-surface-container-low">
                    <span class="material-symbols-outlined text-primary text-[20px]">assignment</span>
                    <span class="font-h3 text-h3 text-on-surface">Chi tiết yêu cầu</span>
                </div>

                @if (! $selected)
                    <x-ui.empty-state icon="inbox" title="Không có yêu cầu để xử lý" />
                @else
                    <div class="p-lg space-y-lg">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-sm">
                                <x-ui.avatar :name="$selected->teacher?->name ?? '?'" />
                                <div>
                                    <h2 class="font-h3 text-h3 text-on-surface">{{ $selected->teacher?->name ?? '—' }}</h2>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ $selected->teacher?->employee_code ?: 'Giáo viên' }}</p>
                                </div>
                            </div>
                            @if ($selected->status === 'pending')
                                <x-ui.badge :color="$selected->isSlaOverdue() ? 'error' : 'success'">{{ $selected->isSlaOverdue() ? 'Quá hạn xử lý' : 'Còn hạn xử lý' }}</x-ui.badge>
                            @else
                                <x-ui.badge :color="$selected->status === 'approved' ? 'success' : 'error'">{{ $selected->status_label }}</x-ui.badge>
                            @endif
                        </div>

                        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-md rounded-lg border border-outline-variant bg-surface-container-low p-md">
                            <div class="sm:col-span-3">
                                <dt class="font-label text-label text-on-surface-variant uppercase">Lớp / Chặng học</dt>
                                <dd class="mt-xs flex items-center gap-xs font-body-medium text-body-medium text-on-surface"><span class="material-symbols-outlined text-[18px] text-primary">school</span>{{ $selected->class_stage_label }}</dd>
                            </div>
                            <div>
                                <dt class="font-label text-label text-on-surface-variant uppercase">Ngày gửi yêu cầu</dt>
                                <dd class="mt-xs flex items-center gap-xs font-body-medium text-body-small text-on-surface"><span class="material-symbols-outlined text-[16px]">calendar_today</span>{{ $selected->created_at->format('d/m/Y') }}</dd>
                            </div>
                            <div>
                                <dt class="font-label text-label text-on-surface-variant uppercase">Số buổi xin thêm</dt>
                                <dd class="mt-xs flex items-center gap-xs font-body-medium text-body-small text-primary"><span class="material-symbols-outlined text-[16px]">add_circle</span>+{{ $selected->extra_sessions ?: 0 }} buổi</dd>
                            </div>
                            <div>
                                <dt class="font-label text-label text-on-surface-variant uppercase">Kết thúc lớp hiện tại</dt>
                                <dd class="mt-xs font-mono text-body-small text-on-surface">{{ $selected->classModel?->end_date?->format('d/m/Y') ?? '—' }}</dd>
                            </div>
                        </dl>

                        <div>
                            <p class="mb-xs font-label text-label text-on-surface-variant uppercase">Lý do xin giãn tiến độ</p>
                            <div class="relative rounded-lg border border-outline-variant bg-surface-container-lowest p-md pl-xl">
                                <span class="material-symbols-outlined absolute left-sm top-sm text-[20px] text-outline">format_quote</span>
                                <p class="whitespace-pre-line font-body-base text-body-base text-on-surface">{{ $selected->reason }}</p>
                                @if ($selected->request_type)<p class="mt-xs font-caption text-caption text-on-surface-variant">{{ $selected->request_type }}</p>@endif
                            </div>
                        </div>

                        @if ($selected->status === 'approved')
                            <div class="rounded-lg border border-tertiary/20 bg-tertiary/5 p-md font-body-small text-body-small text-on-surface">
                                <p class="font-semibold">Đã duyệt bởi {{ $selected->approver?->name }} {{ $selected->reviewed_at ? 'lúc '.$selected->reviewed_at->format('H:i d/m/Y') : '' }}</p>
                                <p class="mt-1">{{ $selected->applied_note }}</p>
                            </div>
                        @elseif ($selected->status === 'rejected')
                            <div class="rounded-lg border border-error/20 bg-error/5 p-md font-body-small text-body-small text-on-surface">
                                <p class="font-semibold">Đã từ chối bởi {{ $selected->approver?->name }} {{ $selected->reviewed_at ? 'lúc '.$selected->reviewed_at->format('H:i d/m/Y') : '' }}</p>
                                <p class="mt-1">Lý do: {{ $selected->rejection_reason ?: '—' }}</p>
                            </div>
                        @elseif ($canReview)
                            <form id="reject-form" method="POST" action="{{ route('syllabus.adjustment-requests.reject', $selected->id) }}" x-show="rejecting" x-cloak class="rounded-lg border border-error/30 bg-error/5 p-md">
                                @csrf
                                <label for="reject-reason" class="mb-xs flex items-center gap-xs font-label text-label text-error"><span class="material-symbols-outlined text-[18px]">warning</span>Lý do từ chối (Bắt buộc)</label>
                                <textarea id="reject-reason" name="rejection_reason" rows="3" placeholder="Nhập lý do chi tiết để phản hồi lại giáo viên..." class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest p-md font-body-base text-body-base focus:border-error focus:outline-none focus:ring-2 focus:ring-error/20">{{ old('rejection_reason') }}</textarea>
                                @error('rejection_reason')<p class="mt-xs font-caption text-caption text-error">{{ $message }}</p>@enderror
                            </form>
                            <form id="approve-form" method="POST" action="{{ route('syllabus.adjustment-requests.approve', $selected->id) }}" x-show="! rejecting">
                                @csrf
                                <x-ui.input type="number" name="extra_sessions" label="Số buổi thêm vào lịch khi duyệt" min="0" :max="\App\Models\SyllabusAdjustmentRequest::MAX_EXTRA_SESSIONS" :value="$selected->extra_sessions ?? 0"
                                            hint="Buổi mới nối tiếp sau buổi cuối của lớp, theo TKB, bỏ qua ngày nghỉ lễ; 0 = chỉ ghi nhận, không đổi lịch." />
                            </form>
                        @endif
                    </div>

                    @if ($canReview && $selected->status === 'pending')
                        <div class="flex items-center justify-end gap-md border-t border-outline-variant bg-surface-container-low px-lg py-md">
                            <div x-show="! rejecting" class="flex items-center gap-md">
                                <x-ui.button variant="danger-text" icon="cancel" @click="rejecting = true">Từ chối</x-ui.button>
                                <x-ui.button type="submit" form="approve-form" icon="check_circle">Phê duyệt</x-ui.button>
                            </div>
                            <div x-show="rejecting" x-cloak class="flex items-center gap-md">
                                <x-ui.button variant="secondary" @click="rejecting = false">Hủy</x-ui.button>
                                <x-ui.button type="submit" form="reject-form" variant="danger">Xác nhận từ chối</x-ui.button>
                            </div>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
