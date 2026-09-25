{{-- Mockup: ui-full-tinh-nang-menglish/epic-7/chi-tiet-cham-cong-theo-gv + roundcuoi 01_Web_Admin/10_doi_soat_chot_bang_cong --}}
<x-app-layout>
    @php
        $punchColors = ['full' => 'success', 'adjusted' => 'info', 'missing_in' => 'warning', 'missing_out' => 'error'];
        $statusColors = ['valid' => 'success', 'invalid' => 'error', 'pending_review' => 'warning'];
        $canReview = $canViewAll && auth()->user()->can('attendance_staff.view');
        $canAdjust = auth()->user()->can('attendance_staff.manual_record');
        $pendingOnPage = $timesheets->getCollection()->where('status', 'pending_review')->pluck('id')->values();
    @endphp

    <div x-data="{
            selected: [],
            pendingIds: @js($pendingOnPage),
            edit: { action: '', timeIn: '', timeOut: '', label: '' },
            reject: { action: '', label: '' },
            toggleAll(e) { this.selected = e.target.checked ? [...this.pendingIds] : []; },
         }">
        <x-ui.page-header :title="$canViewAll ? 'Chi tiết chấm công giáo viên' : 'Chấm công của tôi'"
                          description="Đối soát ca dạy thực tế (check-in / chấm tay) trước khi chốt bảng công và tính lương.">
            <x-slot:actions>
                @can('attendance_staff.sync')
                    <x-ui.button variant="secondary" icon="sync" :href="route('payroll.timesheets.sync-history')">Lịch sử đồng bộ</x-ui.button>
                @endcan
                @if ($canAdjust)
                    <x-ui.button icon="timer" :href="route('payroll.timesheets.manual')">Chấm công thủ công</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Kỳ lương + trạng thái khóa --}}
        <div class="mb-md flex flex-wrap items-center gap-sm">
            <span class="inline-flex items-center gap-xs rounded-lg bg-surface-container-low px-md py-xs font-body-medium text-body-medium text-on-surface">
                <span class="material-symbols-outlined text-[18px] text-primary-container" aria-hidden="true">calendar_month</span>
                Kỳ lương: Tháng {{ $monthStart->format('m/Y') }}
            </span>
            @if ($periodLocked)
                <span class="inline-flex items-center gap-xs rounded-lg bg-error-container px-md py-xs font-body-small text-body-small font-semibold text-on-error-container">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">lock</span>
                    Kỳ lương đã chốt, không thể sửa
                </span>
            @elseif ($period)
                <x-ui.badge color="info">{{ $period->status_label ?? 'Đang mở' }}</x-ui.badge>
            @else
                <x-ui.badge color="neutral">Chưa khởi tạo bảng lương</x-ui.badge>
            @endif
        </div>

        <x-ui.filter-bar placeholder="Tìm giáo viên..." :search="$canViewAll ? 'search' : null">
            <label class="flex items-center gap-sm">
                <span class="font-body-small text-body-small font-medium text-on-surface-variant">Kỳ lương:</span>
                <input type="month" name="month" value="{{ $month }}"
                       class="rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
            </label>
            @if ($canViewAll)
                <x-ui.select name="branch_id" :options="$filterBranches->pluck('name', 'id')" placeholder="Tất cả chi nhánh" aria-label="Chi nhánh" />
                <x-ui.select name="class_id" :options="$filterClasses->mapWithKeys(fn ($c) => [$c->id => $c->name.' ('.$c->code.')'])" placeholder="Tất cả lớp học" aria-label="Lớp học" />
                <x-ui.select name="user_id" :options="$filterTeachers->pluck('name', 'id')" placeholder="Tất cả giáo viên" aria-label="Giáo viên" />
            @endif
            <x-ui.select name="status" :options="['pending_review' => 'Chờ đối soát', 'valid' => 'Hợp lệ', 'invalid' => 'Từ chối']" placeholder="Tất cả trạng thái" aria-label="Trạng thái" />
            <x-ui.button type="submit" variant="secondary" icon="filter_list">Lọc</x-ui.button>
        </x-ui.filter-bar>

        @if ($teacher)
            <div class="mb-lg grid grid-cols-1 gap-md lg:grid-cols-3">
                <div class="flex items-center gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md lg:col-span-2">
                    <x-ui.avatar :name="$teacher->name" size="lg" />
                    <div class="min-w-0">
                        <h2 class="font-h2 text-h2 text-on-surface">{{ $teacher->name }}</h2>
                        <div class="mt-xs flex flex-wrap gap-md font-body-small text-body-small text-on-surface-variant">
                            <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">badge</span>Mã GV: {{ $teacher->employee_code ?: 'Chưa có mã' }}</span>
                            <span class="inline-flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">domain</span>Cơ sở: {{ $teacher->branch?->name ?? 'Chưa gán chi nhánh' }}</span>
                        </div>
                    </div>
                </div>
                <div class="rounded-xl border {{ $missingSessions->isNotEmpty() ? 'border-error/40 bg-error-container/30' : 'border-outline-variant bg-surface-container-lowest' }} p-md">
                    <p class="font-body-small text-body-small text-on-surface-variant">Số lần thiếu chấm công trong tháng</p>
                    <p class="font-h1 text-h1 {{ $missingSessions->isNotEmpty() ? 'text-error' : 'text-on-surface' }}">{{ $missingSessions->count() }} lần</p>
                    <p class="font-body-small text-body-small text-on-surface-variant">Buổi được phân công đã diễn ra nhưng chưa có ca chấm công hợp lệ.</p>
                </div>
            </div>
        @endif

        <div class="mb-md grid grid-cols-1 gap-md sm:grid-cols-3">
            <x-ui.stat-card label="Chờ đối soát" :value="(int) ($summary['pending_review'] ?? 0)" tone="warning" icon="pending_actions" />
            <x-ui.stat-card label="Hợp lệ (tính lương)" :value="(int) ($summary['valid'] ?? 0)" tone="success" icon="task_alt" />
            <x-ui.stat-card label="Từ chối" :value="(int) ($summary['invalid'] ?? 0)" tone="error" icon="block" />
        </div>

        <x-ui.data-table min-width="1000px">
            <x-slot:header>
                <h3 class="font-h3 text-h3 text-on-surface">Ca dạy trong kỳ <span class="font-body-small text-body-small text-on-surface-variant">({{ $timesheets->total() }} bản ghi)</span></h3>
                @if ($canReview && ! $periodLocked)
                    <form method="POST" action="{{ route('payroll.timesheets.bulk-review') }}" class="flex items-center gap-sm">
                        @csrf
                        <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                        <x-ui.button type="submit" icon="task_alt" x-bind:disabled="selected.length === 0">
                            Chốt bảng công (<span x-text="selected.length">0</span>)
                        </x-ui.button>
                    </form>
                @endif
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        @if ($canReview && ! $periodLocked)
                            <th class="w-10"><input type="checkbox" aria-label="Chọn tất cả ca chờ đối soát" @change="toggleAll($event)" :checked="pendingIds.length && selected.length === pendingIds.length" class="rounded border-outline-variant text-primary-container"></th>
                        @endif
                        <th>Ngày</th>
                        <th>Ca học</th>
                        <th>Lớp</th>
                        @if (! $teacher)<th>Giáo viên</th>@endif
                        <th>Giờ vào</th>
                        <th>Giờ ra</th>
                        <th class="text-center">Số giờ</th>
                        <th>Tình trạng</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($timesheets as $ts)
                        @php $rowLocked = $periodLocked || \App\Models\PayrollPeriod::isLockedFor($ts->teaching_date); @endphp
                        <tr>
                            @if ($canReview && ! $periodLocked)
                                <td>
                                    @if ($ts->status === 'pending_review' && ! $rowLocked)
                                        <input type="checkbox" value="{{ $ts->id }}" x-model.number="selected" aria-label="Chọn ca {{ $ts->id }}" class="rounded border-outline-variant text-primary-container">
                                    @endif
                                </td>
                            @endif
                            <td class="whitespace-nowrap font-mono">{{ $ts->teaching_date->format('d/m/Y') }}</td>
                            <td>
                                <span class="block">{{ $ts->scheduled_time ? 'Ca '.$ts->scheduled_time : 'Ngoài lịch' }}</span>
                                <span class="block font-body-small text-body-small text-on-surface-variant">{{ $ts->type_label }} · {{ $ts->source_label }}</span>
                            </td>
                            <td class="font-semibold text-primary">{{ $ts->classModel?->code ?? $ts->classModel?->name ?? '—' }}</td>
                            @if (! $teacher)
                                <td>
                                    @if ($canViewAll)
                                        <a href="{{ request()->fullUrlWithQuery(['user_id' => $ts->user_id, 'page' => null]) }}" class="font-semibold text-on-surface hover:text-primary">{{ $ts->teacher?->name }}</a>
                                    @else
                                        {{ $ts->teacher?->name }}
                                    @endif
                                </td>
                            @endif
                            <td class="font-mono">{{ $ts->checkin_time ?: '--:--' }}@if ($ts->adjusted_at)<span class="text-primary" title="Chỉnh tay">*</span>@endif</td>
                            <td class="font-mono">
                                {{ $ts->display_checkout ?: '--:--' }}@if ($ts->adjusted_at)<span class="text-primary" title="Chỉnh tay">*</span>@endif
                                @if (! $ts->checkout_time && $ts->display_checkout)
                                    <span class="block font-sans text-[11px] text-on-surface-variant">theo lịch</span>
                                @endif
                            </td>
                            <td class="text-center font-mono font-semibold">{{ rtrim(rtrim(number_format((float) $ts->hours, 2, '.', ''), '0'), '.') }}h</td>
                            <td>
                                <x-ui.badge :color="$punchColors[$ts->punch_state] ?? 'neutral'">{{ $ts->punch_state_label }}</x-ui.badge>
                                @if ($ts->adjusted_at)
                                    <span class="mt-xs block max-w-[200px] font-body-small text-body-small italic text-on-surface-variant" title="{{ $ts->adjustment_reason }}">{{ \Illuminate\Support\Str::limit($ts->adjustment_reason, 50) }} — {{ $ts->adjuster?->name }}</span>
                                @elseif ($ts->source === 'manual' && $ts->notes)
                                    <span class="mt-xs block max-w-[200px] font-body-small text-body-small italic text-on-surface-variant" title="Lý do chấm tay">{{ \Illuminate\Support\Str::limit($ts->notes, 50) }}</span>
                                @endif
                            </td>
                            <td>
                                <x-ui.badge :color="$statusColors[$ts->status] ?? 'neutral'">{{ $ts->status === 'pending_review' ? 'Chờ đối soát' : $ts->status_label }}</x-ui.badge>
                                @if ($ts->status === 'invalid' && $ts->rejection_reason)
                                    <span class="mt-xs block max-w-[200px] font-body-small text-body-small text-error">{{ \Illuminate\Support\Str::limit($ts->rejection_reason, 60) }}</span>
                                @elseif ($ts->reviewer && $ts->status === 'valid')
                                    <span class="mt-xs block font-body-small text-body-small text-on-surface-variant">Đã duyệt bởi {{ $ts->reviewer->name }}</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <div class="flex items-center justify-end gap-xs">
                                    @if ($rowLocked)
                                        <span class="inline-flex items-center gap-xs font-body-small text-body-small text-on-surface-variant"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">lock</span>Đã khóa</span>
                                    @else
                                        @if ($canReview && $ts->status === 'pending_review')
                                            <form method="POST" action="{{ route('payroll.timesheets.review', $ts->id) }}">
                                                @csrf
                                                <x-ui.button type="submit" name="decision" value="valid" variant="ghost" size="sm" icon="check">Duyệt</x-ui.button>
                                            </form>
                                            <x-ui.button variant="danger-text" size="sm" icon="close"
                                                         @click="reject = { action: '{{ route('payroll.timesheets.review', $ts->id) }}', label: @js(($ts->teacher?->name ?? '').' — '.$ts->teaching_date->format('d/m/Y')) }; $dispatch('open-modal', 'ts-reject')">Từ chối</x-ui.button>
                                        @endif
                                        @if ($canAdjust)
                                            <x-ui.button variant="ghost" size="sm" icon="edit" aria-label="Chỉnh tay bổ sung"
                                                         @click="edit = { action: '{{ route('payroll.timesheets.adjust', $ts->id) }}', timeIn: @js($ts->checkin_time ?? ''), timeOut: @js($ts->display_checkout ?? ''), label: @js(($ts->teacher?->name ?? '').' — '.($ts->classModel?->code ?? '').' — '.$ts->teaching_date->format('d/m/Y')) }; $dispatch('open-modal', 'ts-adjust')" />
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11"><x-ui.empty-state icon="more_time" title="Chưa có dữ liệu chấm công" description="Không có ca dạy nào khớp bộ lọc trong kỳ lương này." /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer><x-ui.pagination :paginator="$timesheets" unit="ca dạy" /></x-slot:footer>
        </x-ui.data-table>

        @if ($teacher && $missingSessions->isNotEmpty())
            <x-ui.data-table class="mt-lg" min-width="720px">
                <x-slot:header>
                    <h3 class="font-h3 text-h3 text-error">Buổi thiếu chấm công ({{ $missingSessions->count() }})</h3>
                </x-slot:header>
                <table>
                    <thead><tr><th>Ngày</th><th>Ca học</th><th>Lớp</th><th>Giờ vào</th><th>Giờ ra</th><th>Tình trạng</th><th class="text-right">Thao tác</th></tr></thead>
                    <tbody>
                        @foreach ($missingSessions as $session)
                            <tr>
                                <td class="font-mono">{{ $session->date->format('d/m/Y') }}</td>
                                <td>Ca {{ $session->start_time?->format('H:i') }} - {{ $session->end_time?->format('H:i') }}</td>
                                <td class="font-semibold text-primary">{{ $session->classModel?->code ?? $session->classModel?->name }}</td>
                                <td class="font-mono">--:--</td>
                                <td class="font-mono">--:--</td>
                                <td><x-ui.badge color="error">Thiếu chấm công</x-ui.badge></td>
                                <td class="text-right">
                                    @if ($canAdjust && ! $periodLocked)
                                        <x-ui.button variant="ghost" size="sm" icon="timer"
                                                     :href="route('payroll.timesheets.manual', ['user_id' => $teacher->id, 'class_id' => $session->class_id, 'branch_id' => $session->classModel?->branch_id, 'teaching_date' => $session->date->toDateString(), 'time_in' => $session->start_time?->format('H:i'), 'time_out' => $session->end_time?->format('H:i')])">Chấm công tay</x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>
        @endif

        @if ($canAdjust)
            <x-ui.modal name="ts-adjust" title="Chỉnh tay bổ sung" max-width="md" :show="$errors->has('adjustment_reason') || $errors->has('time_in') || $errors->has('time_out')">
                <form method="POST" :action="edit.action" id="ts-adjust-form" class="space-y-md">
                    @csrf
                    @method('PUT')
                    <p class="font-body-small text-body-small text-on-surface-variant" x-text="edit.label"></p>
                    <x-ui.alert type="info">Ca sau khi chỉnh chuyển về <strong>Chờ đối soát</strong>. Kỳ lương đã chốt thì không thể chỉnh sửa.</x-ui.alert>
                    <div class="grid grid-cols-2 gap-md">
                        <x-ui.input type="time" name="time_in" label="Giờ vào" required x-model="edit.timeIn" />
                        <x-ui.input type="time" name="time_out" label="Giờ ra" required x-model="edit.timeOut" />
                    </div>
                    <x-ui.textarea name="adjustment_reason" label="Lý do điều chỉnh" required rows="3" placeholder="Nhập lý do..." />
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'ts-adjust')">Hủy</x-ui.button>
                    <x-ui.button type="submit" form="ts-adjust-form">Lưu thay đổi</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif

        @if ($canReview)
            <x-ui.modal name="ts-reject" title="Từ chối ca dạy" max-width="md" :show="$errors->has('rejection_reason')">
                <form method="POST" :action="reject.action" id="ts-reject-form" class="space-y-md">
                    @csrf
                    <input type="hidden" name="decision" value="invalid">
                    <p class="font-body-small text-body-small text-on-surface-variant" x-text="reject.label"></p>
                    <x-ui.textarea name="rejection_reason" label="Lý do từ chối" required rows="3" placeholder="VD: Không có buổi học trên lịch, trùng ca đã chấm..." />
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'ts-reject')">Hủy</x-ui.button>
                    <x-ui.button type="submit" variant="danger" form="ts-reject-form">Từ chối ca dạy</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    </div>
</x-app-layout>
