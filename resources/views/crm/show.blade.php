<x-app-layout :title="'Chi tiết khách — '.$customer->name">
    @php
        $sub = $latestSubmission ?? $customer->latestSubmission ?? $customer->submissions->first();
        // Chỉ báo "Đã làm bài test" khi có bài / điểm thật: khách chốt thẳng (không test) không hiện huy hiệu rỗng.
        $testSummary = $sub?->scoreSummary() ?? $customer->test_score;
        $hasTested = $sub || filled($customer->test_score) || $customer->stage === 'tested';
        $words = preg_split('/\s+/u', trim($customer->name)) ?: ['?'];
        $initials = mb_strtoupper(mb_substr($words[0], 0, 1).(count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    @endphp

    @php
        // Một cụm thao tác ở góc phải tiêu đề: bước tiếp theo của giai đoạn hiện tại là nút cam duy nhất
        // (Sang bước kế tiếp → nếu không còn bước tay thì Chốt & Xếp lớp → khách Chờ xếp lớp thì Xếp lớp);
        // Phân công lại là nút phụ; Thất bại / Lùi giai đoạn / In nằm trong menu (không có Xóa khách) "⋯".
        $canClose = auth()->user()->can('lead.convert')
            && in_array($customer->stage, \App\Models\CrmCustomer::CLOSABLE_STAGES, true) && ! $customer->converted_student_id;
        $canPlace = $customer->stage === 'waiting_class' && auth()->user()->can('student.assign_class');
        $primaryAction = $stageControls['next'] ? 'next' : ($canClose ? 'close' : ($canPlace ? 'place' : null));
    @endphp

    {{-- Mockup crm-ui-mockup/chi-tiet-khach-hang: tên khách làm tiêu đề (không lặp tiêu đề chung), mã ngắn ở breadcrumb --}}
    <x-ui.page-header :title="$customer->name">
        <x-slot:breadcrumbs>
            <a href="{{ route('crm.customers.index') }}" class="hover:text-primary">Khách hàng</a>
            <span class="material-symbols-outlined text-[14px]">chevron_right</span>
            <span class="font-code" title="{{ $customer->code }}">{{ $customer->short_code }}</span>
        </x-slot:breadcrumbs>
        <x-slot:badges>
            <span class="inline-flex items-center rounded-full border px-sm py-0.5 font-caption text-caption font-bold {{ $customer->stage_badge }}">{{ $customer->stage_label }}</span>
        </x-slot:badges>
        <x-slot:actions>
            @if ($canReassign)
                <x-ui.button variant="secondary" icon="person_add" x-data x-on:click="$dispatch('open-modal', 'reassign-customer')">Phân công lại</x-ui.button>
            @endif
            @if ($canClose)
                <x-ui.button :variant="$primaryAction === 'close' ? 'primary' : 'secondary'" icon="how_to_reg" :href="route('crm.closing-wizard', ['customer_id' => $customer->id])">Chốt &amp; Xếp lớp</x-ui.button>
            @endif
            @if ($canPlace)
                <x-ui.button icon="assignment_turned_in" :href="route('crm.waiting-list')">Xếp lớp</x-ui.button>
            @endif
            @if ($stageControls['next'])
                <form action="{{ route('crm.customers.stage', $customer->id) }}" method="POST" class="inline">
                    @csrf
                    <input type="hidden" name="stage" value="{{ $stageControls['next'] }}" />
                    <x-ui.button type="submit" icon="arrow_forward" title="Chuyển tiến 1 bước">Sang bước: {{ \App\Models\CrmCustomer::stageLabel($stageControls['next']) }}</x-ui.button>
                </form>
            @endif
            <x-ui.dropdown align="right" width="56">
                <x-slot:trigger>
                    <x-ui.button variant="secondary" icon="more_horiz" title="Thao tác khác" aria-label="Thao tác khác" />
                </x-slot:trigger>
                <x-slot:content>
                    @php $menuItem = 'flex w-full items-center gap-sm px-md py-sm text-left font-body-small text-body-small hover:bg-surface-container-low'; @endphp
                    <a href="{{ route('crm.customers.print', $customer->id) }}" target="_blank" class="{{ $menuItem }} text-on-surface">
                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">print</span>In hồ sơ
                    </a>
                    @if ($stageControls['backward'])
                        <button type="button" class="{{ $menuItem }} text-on-surface" onclick="window.dispatchEvent(new CustomEvent('open-modal', { detail: 'crm-stage-backward' }))">
                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">undo</span>Lùi giai đoạn
                        </button>
                    @endif
                    @if ($stageControls['canLose'])
                        <button type="button" class="{{ $menuItem }} text-error" onclick="window.dispatchEvent(new CustomEvent('open-modal', { detail: 'crm-mark-lost' }))">
                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">person_off</span>Thất bại
                        </button>
                    @endif
                </x-slot:content>
            </x-ui.dropdown>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($pendingTransfer)
        <x-ui.alert type="warning" title="Chờ Admin duyệt chuyển cơ sở" class="mb-md">
            {{ $pendingTransfer->requester?->name ?? 'Nhân viên' }} đề nghị chuyển khách sang <strong>{{ $pendingTransfer->toUser?->name }}</strong> phụ trách,
            cơ sở {{ $pendingTransfer->fromBranch?->name ?? '(chưa có)' }} → <strong>{{ $pendingTransfer->toBranch?->name }}</strong>{{ $customer->converted_student_id ? ' (học viên chuyển theo)' : '' }}.
            @can('lead.approve_transfer')
                <a href="{{ route('approvals.index', ['group' => \App\Support\Approvals\ApprovalInboxService::groupSlug(\App\Support\Approvals\ApprovableSource::GROUP_ACADEMIC)]) }}" class="font-bold underline">Duyệt / từ chối</a>
            @endcan
        </x-ui.alert>
    @endif

    @if ($canReassign)
        <x-ui.modal name="reassign-customer" title="Phân công lại người phụ trách" max-width="md" :show="old('_form') === 'reassign' && ($errors->has('reason') || $errors->has('assigned_user_id'))">
            <form id="reassign-form" action="{{ route('crm.customers.reassign', $customer->id) }}" method="POST" class="space-y-3">
                @csrf
                {{-- Lỗi "reason" dùng chung với form lùi giai đoạn / huỷ học thử: chỉ mở lại modal của đúng form đã gửi. --}}
                <input type="hidden" name="_form" value="reassign" />
                <p class="text-body-small text-on-surface-variant">Hiện tại: <strong>{{ $customer->assignedUser?->name ?? 'Chưa phân công' }}</strong>. Thay đổi được ghi vào lịch sử khách.</p>
                <x-ui.select name="assigned_user_id" id="reassign_assigned_user_id" label="Người phụ trách mới" required placeholder="-- Chọn người phụ trách --"
                    :options="\App\Services\Crm\LeadOwners::options($reassignUsers->reject(fn ($u) => $u->id === $customer->assigned_user_id))"
                    hint="Chọn Học vụ cơ sở khác ({{ $customer->branch?->name ?? 'khách chưa có cơ sở' }} là cơ sở hiện tại) = chuyển cơ sở cho khách và học viên, cần Admin duyệt." />
                <x-ui.textarea name="reason" id="reassign_reason" label="Lý do phân công lại" required rows="3" placeholder="VD: Học vụ cũ nghỉ phép, khách chuyển sang học cơ sở khác..." />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'reassign-customer')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="reassign-form" icon="assignment_ind">Phân công lại</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-ui.modal name="crm-mark-lost" id="markLostModal" title="Ghi nhận lý do thất bại" max-width="md">
        <p class="mb-3 text-xs text-on-surface-variant">Khách thất bại được lưu để đối soát và không mở lại.</p>
        <form id="mark-lost-form" action="{{ route('crm.customers.stage', $customer->id) }}" method="POST" class="space-y-3 text-xs">
            @csrf
            <input type="hidden" name="stage" value="lost" />
            <x-ui.textarea name="lost_reason" rows="4" required maxlength="255" placeholder="Ví dụ: chưa phù hợp học phí, lịch học, không liên hệ được..." aria-label="Lý do thất bại" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'crm-mark-lost')">Hủy</x-ui.button>
            <x-ui.button type="submit" form="mark-lost-form" variant="danger">Xác nhận</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    @if ($stageControls['backward'])
    <x-ui.modal name="crm-stage-backward" id="stageBackwardModal" title="Lùi giai đoạn khách" max-width="md">
        <p class="mb-3 text-xs text-on-surface-variant">Chỉ Admin được lùi giai đoạn; lý do được lưu vào lịch sử.</p>
        <form id="stage-backward-form" action="{{ route('crm.customers.stage', $customer->id) }}" method="POST" class="space-y-3 text-xs">
            @csrf
            <x-ui.select name="stage" value="" required aria-label="Giai đoạn lùi về">
                @foreach (array_reverse($stageControls['backward']) as $target)
                    <option value="{{ $target }}">{{ \App\Models\CrmCustomer::stageLabel($target) }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.textarea name="reason" id="stage_backward_reason" rows="3" required placeholder="Lý do lùi giai đoạn (bắt buộc)" aria-label="Lý do lùi giai đoạn" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'crm-stage-backward')">Hủy</x-ui.button>
            <x-ui.button type="submit" form="stage-backward-form" variant="danger">Lùi giai đoạn</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
    @endif

    @if ($canBookTrial)
    {{-- Xếp học thử trước Chốt: lớp khớp trình độ đăng ký, buổi trong 7 ngày tới; mỗi lần 1 buổi, tối đa 2 lần.
         Không tạo ghi danh — khách chỉ vào lớp chính thức khi xếp lớp sau Chốt. --}}
    @php
        $weekdays = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
        $pendingTrial = $trialState['pending'];
        $trialNo = $trialState['used'] + 1;
    @endphp
    <x-ui.modal name="crm-schedule-trial" id="scheduleTrialModal" :show="$errors->has('class_session_id')" title="{{ $pendingTrial ? 'Xếp học thử' : 'Xếp học thử — buổi '.$trialNo.'/'.\App\Models\CrmTrialBooking::MAX_ACTIVE_PER_LEAD }}" max-width="lg">
        <div class="mb-3 space-y-1 text-xs">
            <p class="text-on-surface-variant">Chọn 1 buổi học thật trong 7 ngày tới của lớp cùng trình độ{{ $customer->branch ? ' tại '.$customer->branch->name : '' }}. Giáo viên buổi đó nhận ghi chú, nhận xét khách sau giờ học và Học vụ được báo để chăm sóc.</p>
            <p><span class="text-on-surface-variant">Trình độ đăng ký:</span> <span class="font-semibold text-on-surface">{{ $trialSlots['level'] ?? 'Chưa có (hiện mọi lớp đang mở)' }}</span></p>
        </div>
        @if ($pendingTrial)
            <x-ui.alert type="info">Khách đang có buổi học thử {{ $pendingTrial->classModel?->name }} ngày {{ $pendingTrial->session?->date?->format('d/m/Y') }} {{ $pendingTrial->session?->start_time?->format('H:i') }}. Buổi tiếp theo xếp lại sau khi buổi này kết thúc.</x-ui.alert>
        @else
        <form id="schedule-trial-form" action="{{ route('crm.customers.trial-bookings.store', $customer->id) }}" method="POST" class="space-y-3 text-xs">
            @csrf
            <div class="max-h-[55vh] space-y-2 overflow-y-auto">
                @forelse ($trialSlots['classes'] as $row)
                    @php $class = $row['class']; @endphp
                    <div class="rounded-xl border border-surface-container-highest p-2.5" data-testid="trial-class">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-2">
                            <span class="font-bold text-on-surface">{{ $class->name }}</span>
                            <span class="text-on-surface-variant">{{ $class->course?->name ?? 'Chưa gán khóa' }}{{ $class->level ? ' · '.$class->level : '' }}</span>
                        </div>
                        @if ($row['sessions']->isEmpty())
                            <p class="mt-1 italic text-on-surface-subtle">Không có buổi học trong 7 ngày tới.</p>
                        @else
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($row['sessions'] as $slot)
                                    <label class="cursor-pointer">
                                        <input type="radio" name="class_session_id" value="{{ $slot->id }}" class="peer sr-only" @checked((int) old('class_session_id') === $slot->id) required>
                                        <span class="inline-flex flex-col rounded-lg border border-outline-variant px-2.5 py-1.5 leading-tight hover:border-secondary peer-checked:border-secondary peer-checked:bg-secondary/10 peer-checked:ring-1 peer-checked:ring-secondary peer-focus-visible:ring-2 peer-focus-visible:ring-secondary">
                                            <span class="font-semibold text-on-surface">{{ $weekdays[$slot->date->dayOfWeek] }} {{ $slot->date->format('d/m') }} · {{ $slot->start_time?->format('H:i') }}–{{ $slot->end_time?->format('H:i') }}</span>
                                            <span class="text-xs text-on-surface-variant">GV: {{ $slot->teacher?->name ?? $class->teacher?->name ?? 'Chưa gán' }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="rounded-xl border border-surface-container-highest p-4 text-center text-on-surface-subtle">
                        {{ $trialSlots['filtered'] ? 'Không có lớp đang mở nào khớp trình độ '.$trialSlots['level'].($customer->branch ? ' tại '.$customer->branch->name : '').'.' : 'Chưa có lớp đang mở'.($customer->branch ? ' tại '.$customer->branch->name : '').'.' }}
                        <span class="block mt-1">Kiểm tra "Khóa học quan tâm" của khách hoặc lịch lớp.</span>
                    </div>
                @endforelse
            </div>
            @error('class_session_id')<p class="font-semibold text-error">{{ $message }}</p>@enderror
            <x-ui.textarea name="notes" rows="2" label="Ghi chú cho giáo viên" placeholder="Trình độ, mục tiêu, tính cách của bé..." :value="old('notes')" />
        </form>
        @endif
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'crm-schedule-trial')">{{ $pendingTrial ? 'Đóng' : 'Hủy' }}</x-ui.button>
            @unless ($pendingTrial)
                <x-ui.button type="submit" form="schedule-trial-form" :disabled="$trialSlots['classes']->every(fn ($row) => $row['sessions']->isEmpty())">Lưu lịch học thử</x-ui.button>
            @endunless
        </x-slot:footer>
    </x-ui.modal>
    @endif

    {{-- Schedule Test Modal --}}
    <x-ui.modal name="crm-schedule-test" id="scheduleTestModal" title="Hẹn lịch test đầu vào" max-width="md">
        <form id="schedule-test-form" action="{{ route('crm.customers.schedule-test', $customer->id) }}" method="POST" class="space-y-3 text-xs">
            @csrf
            <div class="grid grid-cols-2 gap-3">
                @php
                    // Hẹn lại: giữ lịch / đề / người chấm đang có; lịch mới mặc định sáng mai 09:00 (giờ trong quá khứ bị từ chối).
                    $prefillAt = $customer->appointment_at?->isFuture() ? $customer->appointment_at : today()->addDay()->setTime(9, 0);
                @endphp
                <x-ui.date name="appointment_date" id="modal_appointment_date" label="Ngày hẹn test" :value="$prefillAt->format('Y-m-d')" required />
                <x-ui.input type="time" name="appointment_time" id="modal_appointment_time" label="Giờ hẹn" :value="$prefillAt->format('H:i')" required />
            </div>

            <x-ui.select name="appointment_type" id="modal_appointment_type" label="Hình thức làm bài" :value="$customer->appointment_type ?? 'online'" required class="font-bold !text-primary"
                         :options="['online' => 'Trực tuyến (Online qua link Portal)', 'offline' => 'Tại cơ sở (Offline tại trung tâm)']" />

            <x-ui.select name="assigned_test_id" id="modal_assigned_test_id" label="Đề test gán cho khách" :value="(string) ($customer->assigned_test_id ?? '')"
                         :options="collect($placementTests ?? [])->mapWithKeys(fn ($test) => [$test->id => $test->title.' ('.$test->code.')'])" />

            <x-ui.select name="examiner_id" id="modal_examiner_id" label="Giáo viên / Giám thị phụ trách chấm" :value="(string) ($customer->examiner_id ?? '')" placeholder="-- Tự động chấm AI / Chưa gán --"
                         :options="collect($examiners ?? [])->pluck('name', 'id')" />

            <x-ui.textarea name="notes" id="modal_test_notes" label="Ghi chú nhắc hẹn" rows="2" placeholder="Nhắc học viên mang theo tai nghe, CMND..." />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="$dispatch('close-modal', 'crm-schedule-test')">Hủy</x-ui.button>
            <x-ui.button type="submit" form="schedule-test-form" variant="info" size="sm" class="font-bold">Xác nhận lịch hẹn</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Enter / Edit Test Score Modal --}}
    <x-ui.modal name="crm-edit-test-score" id="editTestScoreModal" title="Nhập điểm test đầu vào" max-width="2xl">
        <form id="edit-test-score-form" action="{{ route('crm.customers.save-test-score', $customer->id) }}" method="POST" class="space-y-3.5 text-xs">
            @csrf
                @php
                    // Chỉ điền sẵn khi sửa đúng lần thi đang chọn; nhập lần mới thì để trống, không bịa điểm mặc định.
                    $editSub = ($sub && $customer->stage !== 'test_scheduled') ? $sub : null;
                    $scoreTestId = old('placement_test_id', $editSub?->placement_test_id ?? $customer->assigned_test_id);
                @endphp
                @if ($editSub)
                    <input type="hidden" name="submission_id" value="{{ $editSub->id }}">
                @endif
                <x-ui.alert type="warning" class="text-xs">
                    <strong>Học viên:</strong> {{ $customer->name }} ({{ $customer->phone }})<br>
                    <span>Chấm theo <strong>thang điểm khối lớp</strong>: Tổng = Nghe + Đọc &amp; Viết + Nói → lớp đề xuất. Lưu điểm sẽ tự chuyển khách sang "Đã test".</span>
                </x-ui.alert>

                @unless ($editSub)
                    <x-ui.select name="placement_test_id" id="modal_placement_test_id" label="Đề kiểm tra đã dùng" required placeholder="— Chọn đề —" :value="(string) $scoreTestId" class="font-semibold">
                        @foreach ($placementTests as $t)
                            <option value="{{ $t->id }}" @selected((string) $scoreTestId === (string) $t->id)>[{{ $t->code }}] {{ $t->title }}</option>
                        @endforeach
                    </x-ui.select>
                @endunless

                @include('placement-tests.partials.rubric-score-fields', [
                    'submission' => $editSub,
                    'defaultGroup' => \App\Services\PlacementRubricService::detectGradeGroup($editSub?->test?->code ?? $customer->assignedTest?->code),
                ])
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="$dispatch('close-modal', 'crm-edit-test-score')">Hủy</x-ui.button>
            @if (! $editSub || $editSub->isPending())
                <x-ui.button type="submit" form="edit-test-score-form" name="action" value="draft" variant="secondary">Lưu bản nháp</x-ui.button>
            @endif
            <x-ui.button type="submit" form="edit-test-score-form" name="action" value="confirm" icon="check">Xác nhận kết quả</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>


    @if ($customer->stage === \App\Models\CrmCustomer::STAGE_LOST)
        <x-ui.alert type="error" class="mb-lg" data-testid="lost-banner">
            <div class="font-semibold">Khách Thất bại{{ $customer->lost_at ? ' từ '.$customer->lost_at->format('d/m/Y') : '' }} — không mở lại, giữ để đối soát.</div>
            @if ($customer->lost_reason)<div>Lý do: {{ $customer->lost_reason }}</div>@endif
        </x-ui.alert>
    @endif

    @if ($customer->stage === 'waiting_class')
        <x-ui.alert type="warning" class="mb-lg" title="Đã chốt, chờ xếp lớp{{ $customer->waiting_since ? ' từ '.$customer->waiting_since->format('d/m/Y') : '' }}">
            Khóa: {{ $customer->waitingCourse?->name ?? 'Chưa chọn khóa' }} · {{ $customer->waitingBranch?->name ?? $customer->branch?->name }}
            · Học phí đăng ký: {{ $customer->fee_paid_at_closing ? 'Đã đóng' : 'Chưa đóng (đã tạo task nhắc thu)' }}
        </x-ui.alert>
    @endif

    @php
        $submission = $sub;
        $hasResult = ! empty($submission) || ! empty($customer->test_score);
        $hasScheduled = ! empty($customer->appointment_at) || ! empty($customer->assigned_test_id);
        $resultLogs = $customer->histories->where('type', 'result');
        $card = 'rounded-xl border border-surface-container-highest bg-surface-container-lowest shadow-sm';
        // Nút "Lưu …" trong khối: kiểu phụ, chỉ tô cam khi form đang được sửa (x-bind:class khi dirty).
        $dirtySave = '!border-transparent !bg-primary-container !text-white hover:!bg-primary';
    @endphp

    <div class="grid grid-cols-1 gap-lg lg:grid-cols-12">
        {{-- ── Cột trái: thông tin, trạng thái & hạn xử lý, chăm sóc tháng đầu ── --}}
        <div class="space-y-lg lg:col-span-4">
            <div class="{{ $card }} p-lg">
                <div class="mb-lg flex items-center gap-md">
                    <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-primary-fixed font-h2 text-h2 text-on-primary-fixed">{{ $initials }}</div>
                    <div class="min-w-0">
                        {{-- Tên + giai đoạn đã ở tiêu đề trang: thẻ chỉ còn thông tin liên hệ --}}
                        <h2 class="font-h3 text-h3 text-on-surface">Thông tin liên hệ</h2>
                        <div class="mt-xs flex flex-wrap items-center gap-xs">
                            @if ($hasTested)
                                <span class="inline-flex items-center gap-xs rounded-full bg-tertiary/10 px-sm py-0.5 font-caption text-caption font-bold text-tertiary">
                                    <span class="material-symbols-outlined text-[14px]">task_alt</span>{{ $sub?->isPending() ? 'Đã làm bài test, chờ chấm' : 'Đã làm bài test' }}@if (filled($testSummary)) ({{ $testSummary }})@endif
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
                <dl class="space-y-md font-body-base text-body-base">
                    @foreach ([
                        ['Số điện thoại', $customer->phone, true],
                        ['Tên phụ huynh', $customer->parent_name ?: '—', false],
                        ['SĐT phụ huynh', $customer->parent_phone ?: '—', true],
                        ['Nguồn', $customer->source ?? 'Trực tiếp', false],
                        ['Người phụ trách', $customer->assignedUser?->name ?? 'Chưa phân công', false],
                        ['Chi nhánh', $customer->branch?->name ?? 'Chưa gán cơ sở', false],
                    ] as [$label, $value, $mono])
                        <div class="flex items-start justify-between gap-md border-b border-surface-container pb-sm last:border-0 last:pb-0">
                            <dt class="text-on-surface-variant">{{ $label }}</dt>
                            <dd class="text-right font-medium text-on-surface {{ $mono ? 'font-code' : '' }}">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            {{-- Trạng thái & Hạn xử lý --}}
            <div class="{{ $card }} p-lg">
                <h3 class="mb-md font-h3 text-h3 text-on-surface">Trạng thái &amp; Hạn xử lý</h3>
                <div class="space-y-md">
                    <div class="rounded-lg border-l-4 border-primary-container bg-surface-container-low p-md">
                        <p class="font-label text-label uppercase text-on-surface-variant">Giai đoạn hiện tại</p>
                        <p class="font-h3 text-h3 text-primary">{{ $customer->stage_label }}</p>
                        <p class="font-caption text-caption text-on-surface-variant">{{ $statusCard['days_in_stage'] }} ngày ở giai đoạn này (từ {{ $statusCard['stage_since']->format('d/m/Y') }})</p>
                    </div>
                    <div class="grid grid-cols-2 gap-md">
                        <div class="rounded-lg bg-surface-container-low p-md">
                            <p class="font-label text-label uppercase text-on-surface-variant">Liên hệ gần nhất</p>
                            <p class="font-body-medium text-body-medium text-on-surface">{{ $statusCard['last_contact']?->format('d/m/Y H:i') ?? 'Chưa có' }}</p>
                        </div>
                        <div class="rounded-lg p-md {{ $statusCard['follow_up_status'] === 'overdue' ? 'bg-error-container/40' : ($statusCard['follow_up_status'] === 'due_soon' ? 'bg-warning-container' : 'bg-surface-container-low') }}">
                            <p class="font-label text-label uppercase text-on-surface-variant">Hạn liên hệ tiếp theo</p>
                            @if ($customer->next_follow_up_at)
                                <div class="flex items-center gap-xs {{ $statusCard['follow_up_status'] === 'overdue' ? 'text-error' : ($statusCard['follow_up_status'] === 'due_soon' ? 'text-on-warning-container' : 'text-on-surface') }}">
                                    <span class="material-symbols-outlined text-[18px]">timer</span>
                                    <span class="font-body-semibold text-body-semibold">{{ $statusCard['follow_up_remaining'] }}</span>
                                </div>
                                <p class="font-code text-caption text-on-surface-variant">{{ $customer->next_follow_up_at->format('H:i d/m/Y') }}</p>
                                @if ($statusCard['follow_up_status'] === 'overdue')
                                    <x-ui.badge color="status-overdue">Quá hạn</x-ui.badge>
                                @elseif ($statusCard['follow_up_status'] === 'due_soon')
                                    <x-ui.badge color="warning">Sắp hết hạn</x-ui.badge>
                                @endif
                            @else
                                <p class="font-body-medium text-body-medium text-on-surface-variant">Chưa đặt</p>
                            @endif
                        </div>
                    </div>
                    @if ($statusCard['neglected'])
                        <x-ui.alert type="error">Khách chưa có hoạt động chăm sóc nào trong {{ $statusCard['neglect_days'] }} ngày gần đây.</x-ui.alert>
                    @endif
                    @can('lead.update')
                        {{-- Mở tab "Thông tin khách hàng" ngay trên trang, cuộn tới và focus ô "Hạn liên hệ tiếp theo" --}}
                        <a href="{{ route('crm.customers.show', ['id' => $customer->id, 'tab' => 'info']) }}#next_follow_up_at" x-data
                           @click.prevent="$dispatch('crm-focus-follow-up')" data-testid="set-follow-up"
                           class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-primary hover:underline">
                            <span class="material-symbols-outlined text-[16px]">event</span>Đặt hạn liên hệ
                        </a>
                    @endcan
                </div>
            </div>

            {{-- Chăm sóc tháng đầu: chỉ khi đã chuyển đổi (có hồ sơ học viên) --}}
            @if ($customer->converted_student_id)
                @php $careState = $customer->care_checklist ?? []; @endphp
                <form action="{{ route('crm.customers.care-checklist', $customer->id) }}" method="POST" class="{{ $card }} space-y-md p-lg" x-data="{ dirty: false }" x-on:input="dirty = true" x-on:change="dirty = true">
                    @csrf
                    <div class="flex items-center justify-between gap-sm">
                        <h3 class="font-h3 text-h3 text-on-surface">Chăm sóc tháng đầu</h3>
                        <span class="font-caption text-caption text-on-surface-variant">{{ collect($careState)->only(array_keys(\App\Models\CrmCustomer::CARE_CHECKLIST_ITEMS))->filter()->count() }}/{{ count(\App\Models\CrmCustomer::CARE_CHECKLIST_ITEMS) }} mốc</span>
                    </div>
                    <div class="space-y-sm">
                        @foreach (\App\Models\CrmCustomer::CARE_CHECKLIST_ITEMS as $key => $label)
                            @php $done = ! empty($careState[$key]); @endphp
                            <label class="flex cursor-pointer items-start gap-sm rounded-lg p-sm {{ $done ? 'bg-tertiary/5' : 'hover:bg-surface-container-low' }}">
                                <input type="checkbox" name="items[]" value="{{ $key }}" @checked($done) @cannot('lead.update') disabled @endcannot class="peer sr-only">
                                <span class="material-symbols-outlined {{ $done ? 'text-tertiary' : 'text-outline' }}" @if ($done) style="font-variation-settings: 'FILL' 1;" @endif>{{ $done ? 'check_circle' : 'radio_button_unchecked' }}</span>
                                <span class="min-w-0">
                                    <span class="block font-body-medium text-body-medium {{ $done ? 'text-on-surface' : 'text-on-surface-variant' }}">{{ $label }}</span>
                                    @if (! empty($careState[$key]['done_at']))
                                        <span class="block font-caption text-caption text-on-surface-variant">Hoàn thành: {{ \Illuminate\Support\Carbon::parse($careState[$key]['done_at'])->format('d/m/Y') }}{{ ! empty($careState[$key]['by']) ? ' · '.$careState[$key]['by'] : '' }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @can('lead.update')
                        <script>
                            // Bấm vào dòng đổi biểu tượng tick ngay (checkbox ẩn vẫn gửi đi khi Lưu).
                            document.currentScript.closest('form').addEventListener('change', e => {
                                const box = e.target; if (box.name !== 'items[]') return;
                                const icon = box.nextElementSibling;
                                icon.textContent = box.checked ? 'check_circle' : 'radio_button_unchecked';
                                icon.classList.toggle('text-tertiary', box.checked); icon.classList.toggle('text-outline', !box.checked);
                                icon.style.fontVariationSettings = box.checked ? "'FILL' 1" : '';
                            });
                        </script>
                        <div class="flex items-center gap-sm">
                            <div class="flex-1"><x-ui.input name="note" maxlength="1000" placeholder="Ghi chú chăm sóc (tuỳ chọn)" aria-label="Ghi chú chăm sóc" class="font-body-small text-body-small" /></div>
                            <x-ui.button type="submit" variant="secondary" size="sm" icon="save" x-bind:class="dirty && '{{ $dirtySave }}'">Lưu checklist</x-ui.button>
                        </div>
                    @endcan
                </form>
            @endif
        </div>

        {{-- ── Cột phải: thao tác (tab) + lịch sử hoạt động ── --}}
        <div class="space-y-lg lg:col-span-8">
            <div class="{{ $card }} overflow-hidden" x-data="{
                     tab: @js(old('_tab', request('tab')) === 'info' ? 'info' : 'ops'),
                     focusFollowUp() {
                         const el = document.getElementById('next_follow_up_at');
                         if (! el) return;
                         this.tab = 'info';
                         const details = el.closest('details');
                         if (details) details.open = true;
                         this.$nextTick(() => { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); el.focus({ preventScroll: true }); });
                     },
                 }"
                 x-init="if (location.hash === '#next_follow_up_at') focusFollowUp()"
                 @crm-focus-follow-up.window="focusFollowUp()">
                <div class="flex border-b border-surface-container-highest" role="tablist">
                    <button type="button" role="tab" @click="tab = 'ops'" :class="tab === 'ops' ? 'border-primary-container text-primary font-semibold' : 'border-transparent text-on-surface-variant hover:text-primary'"
                            class="-mb-px border-b-2 px-lg py-md font-body-medium text-body-medium transition-colors">Đặt lịch &amp; Kết quả</button>
                    <button type="button" role="tab" @click="tab = 'info'" :class="tab === 'info' ? 'border-primary-container text-primary font-semibold' : 'border-transparent text-on-surface-variant hover:text-primary'"
                            class="-mb-px border-b-2 px-lg py-md font-body-medium text-body-medium transition-colors">Thông tin khách hàng</button>
                </div>

                <div x-show="tab === 'ops'" class="space-y-xl p-lg">
                    {{-- Lịch hẹn Test + link test online --}}
                    <section class="space-y-md">
                        <div class="flex flex-wrap items-center justify-between gap-sm">
                            <h4 class="flex items-center gap-sm font-h3 text-h3 text-on-surface">
                                <span class="material-symbols-outlined text-secondary">event_available</span>Lịch hẹn Test
                            </h4>
                            @if ($hasResult)
                                <x-ui.badge color="success">Đã có kết quả</x-ui.badge>
                            @elseif ($hasScheduled)
                                <x-ui.badge color="info">Đã gửi link</x-ui.badge>
                            @else
                                <x-ui.badge color="error">Chưa gửi đề</x-ui.badge>
                            @endif
                        </div>

                        @if (! $hasResult && ! $hasScheduled && ! in_array($customer->stage, ['consulting', 'test_scheduled', 'testing', 'tested'], true))
                            <p class="font-body-small text-body-small text-on-surface-variant">
                                {{ $customer->stage === 'new' ? 'Khách đang ở bước Mới: Học vụ / Quản lý cơ sở chuyển sang Đang tư vấn rồi mới hẹn test.' : 'Không hẹn test ở giai đoạn '.$customer->stage_label.'.' }}
                            </p>
                        @elseif (! $hasResult && ! $hasScheduled)
                            @can('entrance_test.send')
                                <form action="{{ route('crm.customers.schedule-test', $customer->id) }}" method="POST" class="space-y-md">
                                    @csrf
                                    <input type="hidden" name="appointment_type" value="online" />
                                    @php
                                        $testGroups = $placementTests->mapWithKeys(fn ($t) => [$t->id => $t->grade_level ?? \App\Models\PlacementTest::detectGradeLevel($t->code)]);
                                        $levelOptions = \App\Models\PlacementTest::GRADE_LEVELS;
                                    @endphp
                                    @if ($placementTests->isEmpty())
                                        <x-ui.alert type="warning">
                                            Chưa có đề test đầu vào nào đang mở.
                                            @can('placement_test.create')
                                                <a href="{{ route('placement-tests.create') }}" class="font-body-semibold underline">Tạo đề test</a> (chọn cấp độ khi tạo đề) rồi quay lại hẹn test.
                                            @else
                                                Nhờ Quản lý cơ sở / Admin tạo đề ở mục Test đầu vào &amp; học thử → Đề test đầu vào.
                                            @endcan
                                        </x-ui.alert>
                                    @endif
                                    {{-- Mockup: "Chọn cấp độ" → "Danh sách đề tương ứng" --}}
                                    <div class="grid grid-cols-1 gap-md sm:grid-cols-2" x-data="{ level: '', groups: @js($testGroups), testId: @js((string) old('assigned_test_id', $placementTests->first()?->id)) }">
                                        <x-ui.field label="Chọn cấp độ" for="test_level">
                                            <x-ui.select id="test_level" x-model="level" x-on:change="const first = Object.keys(groups).find((id) => !level || groups[id] === level); testId = first ?? ''">
                                                <option value="">Tất cả cấp độ</option>
                                                @foreach ($levelOptions as $groupKey => $groupLabel)
                                                    <option value="{{ $groupKey }}">{{ $groupLabel }}</option>
                                                @endforeach
                                            </x-ui.select>
                                        </x-ui.field>
                                        <x-ui.field label="Danh sách đề tương ứng" name="assigned_test_id" for="assigned_test_id">
                                            <x-ui.select id="assigned_test_id" name="assigned_test_id" value="" x-model="testId">
                                                @foreach ($placementTests as $t)
                                                    <option value="{{ $t->id }}" x-show="!level || groups[{{ $t->id }}] === level">[{{ $t->code }}] {{ $t->title }}{{ $t->duration_minutes ? ' ('.$t->duration_minutes.'\')' : '' }}</option>
                                                @endforeach
                                            </x-ui.select>
                                            <p x-cloak x-show="level && !Object.values(groups).includes(level)" class="mt-xs font-caption text-caption text-danger">
                                                Chưa có đề cho cấp độ này.
                                                @can('placement_test.create')
                                                    <a href="{{ route('placement-tests.create') }}" class="underline">Tạo đề</a>
                                                @endcan
                                            </p>
                                        </x-ui.field>
                                        <x-ui.date name="appointment_date" label="Ngày hẹn làm test" required min="{{ now()->toDateString() }}" :value="old('appointment_date', now()->addDay()->toDateString())" />
                                        <x-ui.input type="time" name="appointment_time" label="Giờ hẹn" required :value="old('appointment_time', '09:00')" />
                                    </div>
                                    <div class="flex flex-wrap items-center justify-end gap-sm">
                                        @can('entrance_test.grade')
                                            <x-ui.button variant="secondary" icon="edit_note" onclick="window.dispatchEvent(new CustomEvent('open-modal', { detail: 'crm-edit-test-score' }))">Nhập điểm trực tiếp</x-ui.button>
                                        @endcan
                                        <x-ui.button type="submit" icon="send">Gửi link test online</x-ui.button>
                                    </div>
                                </form>
                            @else
                                <p class="font-body-small text-body-small text-on-surface-variant">Chưa hẹn test. Học vụ / Quản lý cơ sở gửi link test cho khách.</p>
                            @endcan
                        @elseif (! $hasResult && $hasScheduled)
                            <div class="flex items-start justify-between gap-md rounded-lg border border-secondary/30 bg-info-container p-md">
                                <div class="flex items-start gap-sm">
                                    <span class="material-symbols-outlined mt-0.5 text-secondary">schedule_send</span>
                                    <div>
                                        <p class="font-body-semibold text-body-semibold text-on-surface">Đã gửi link — chờ khách làm bài</p>
                                        <p class="font-caption text-caption italic text-info">Hẹn lúc {{ $customer->appointment_at?->format('H:i, d/m/Y') ?? '—' }} · {{ $customer->assignedTest?->title ?? 'Chưa gán đề' }}{{ $customer->assignedTest?->duration_minutes ? ' · '.$customer->assignedTest->duration_minutes.' phút' : '' }}</p>
                                    </div>
                                </div>
                                @can('entrance_test.grade')
                                    <x-ui.button variant="secondary" size="sm" icon="edit" onclick="window.dispatchEvent(new CustomEvent('open-modal', { detail: 'crm-edit-test-score' }))">Nhập điểm ngay</x-ui.button>
                                @endcan
                            </div>
                            <p class="font-body-small text-body-small text-on-surface-variant">Cấp độ: <strong class="text-on-surface">{{ \App\Models\PlacementTest::gradeLevelLabel($customer->assignedTest?->grade_level ?? \App\Models\PlacementTest::detectGradeLevel($customer->assignedTest?->code)) ?? 'Chưa chọn cấp độ' }}</strong></p>
                            @if ($portalTestLink)
                                <div class="flex flex-wrap gap-sm" x-data="{ testLink: @js($portalTestLink) }">
                                    <x-ui.button variant="secondary" size="sm" icon="refresh" x-on:click="navigator.clipboard.writeText(testLink); $dispatch('toast', { message: 'Đã tạo và sao chép link mới (hiệu lực {{ \App\Services\PlacementPortalLinkService::LINK_TTL_DAYS }} ngày) — gửi lại cho khách qua Zalo/SMS.', type: 'success' })">Gửi lại link</x-ui.button>
                                    <x-ui.button variant="secondary" size="sm" icon="open_in_new" :href="$portalTestLink" target="_blank">Mở cổng test</x-ui.button>
                                    <x-ui.button variant="secondary" size="sm" icon="content_copy" x-on:click="navigator.clipboard.writeText(testLink); $dispatch('toast', { message: 'Đã sao chép đường dẫn bài test.', type: 'success' })">Sao chép link test</x-ui.button>
                                    <p class="w-full font-caption text-caption italic text-on-surface-variant">Link riêng của khách, hiệu lực {{ \App\Services\PlacementPortalLinkService::LINK_TTL_DAYS }} ngày kể từ lúc mở trang này.</p>
                                </div>
                            @else
                                <x-ui.alert type="warning">Khách chưa được gán đề test đang hoạt động nên chưa thể tạo link làm bài.</x-ui.alert>
                            @endif
                        @else
                            <p class="font-body-small text-body-small text-on-surface-variant">
                                Lịch hẹn: {{ $customer->appointment_at?->format('H:i, d/m/Y') ?? 'Làm bài không qua lịch hẹn' }}{{ $sub?->test ? ' · Đề: '.$sub->test->title : '' }}
                            </p>
                            @can('entrance_test.send')
                                @if (in_array($customer->stage, ['consulting', 'test_scheduled', 'tested'], true))
                                    <x-ui.button variant="ghost" size="sm" icon="event_repeat" onclick="window.dispatchEvent(new CustomEvent('open-modal', { detail: 'crm-schedule-test' }))">Hẹn test lại</x-ui.button>
                                @endif
                            @endcan
                        @endif
                    </section>

                    {{-- Thứ tự theo luồng: Lịch test → Kết quả → Gửi kết quả → Học thử. Khối chưa tới giai đoạn thu về một dòng xám. --}}
                    @php
                        $collapsedRow = 'flex flex-wrap items-center justify-between gap-sm border-t border-surface-container-highest pt-lg font-body-medium text-body-medium text-on-surface-variant';
                    @endphp

                    {{-- Kết quả & Đánh giá (thang điểm khối lớp — A6 Q2) --}}
                    @if (! $hasResult)
                        <div class="{{ $collapsedRow }}">
                            <span class="flex items-center gap-sm"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">assignment_turned_in</span>Kết quả &amp; Đánh giá</span>
                            <span class="font-caption text-caption">Chưa có kết quả test đầu vào</span>
                        </div>
                        @if (($unlinkedSubmissions ?? collect())->isNotEmpty())
                            {{-- Bài nộp qua link công khai chưa tự khớp được khách (SĐT gõ khác...): Học vụ gắn tay --}}
                            <div class="space-y-sm rounded-lg border border-warning/40 bg-warning-container/40 p-md" data-testid="unlinked-submissions">
                                <p class="font-body-small text-body-small font-semibold text-on-surface">Có bài test chưa gắn với khách nào, trùng SĐT hoặc tên của khách này:</p>
                                @foreach ($unlinkedSubmissions as $candidate)
                                    <div class="flex flex-wrap items-center justify-between gap-sm rounded-lg bg-surface-container-lowest px-md py-sm font-body-small text-body-small">
                                        <div class="min-w-0">
                                            <div class="font-semibold text-on-surface">{{ $candidate->candidate_name }} · {{ $candidate->candidate_phone }}</div>
                                            <div class="text-on-surface-variant">
                                                {{ $candidate->test?->title ?? 'Đề đã xoá' }} · nộp {{ $candidate->created_at?->format('H:i d/m/Y') }}
                                                · {{ $candidate->isPending() ? 'Chờ chấm' : ($candidate->scoreSummary() ?? 'Đã chấm') }}
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-xs">
                                            @can('placement_test.grade')
                                                <x-ui.button variant="ghost" size="sm" icon="visibility" :href="route('placement-tests.results.show', $candidate->id)">Xem bài</x-ui.button>
                                            @endcan
                                            <form method="POST" action="{{ route('crm.customers.link-submission', $customer->id) }}">
                                                @csrf
                                                <input type="hidden" name="submission_id" value="{{ $candidate->id }}">
                                                <x-ui.button type="submit" variant="secondary" size="sm" icon="link">Gắn vào khách này</x-ui.button>
                                            </form>
                                        </div>
                                    </div>
                                @endforeach
                                @error('submission_id')<p class="text-error">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    @else
                    <section class="space-y-md border-t border-surface-container-highest pt-lg">
                        <div class="flex flex-wrap items-center justify-between gap-sm">
                            <h4 class="flex items-center gap-sm font-h3 text-h3 text-on-surface">
                                <span class="material-symbols-outlined text-tertiary">assignment_turned_in</span>Kết quả &amp; Đánh giá
                                @if ($rubric && ! $rubric['legacy'] && $rubric['has_rubric'])
                                    <span class="inline-flex items-center gap-xs rounded-full bg-secondary/10 px-sm py-0.5 font-caption text-caption font-bold text-secondary"><span class="material-symbols-outlined text-[14px]">auto_awesome</span>Thang điểm tự động</span>
                                @endif
                            </h4>
                            @if ($submission)
                                <x-ui.button variant="secondary" size="sm" icon="picture_as_pdf" :href="\Illuminate\Support\Facades\URL::signedRoute('portal.test.scorecard', ['id' => $submission->id])" target="_blank">Tải kết quả (PDF)</x-ui.button>
                            @endif
                        </div>
                            @include('placement-tests.partials.rubric-result', ['submission' => $submission, 'rubric' => $rubric, 'fallbackScore' => $customer->test_score])
                            <div class="flex flex-wrap items-center justify-between gap-sm">
                                <div class="flex flex-wrap items-center gap-sm">
                                    @if ($submission)
                                        <x-ui.button variant="secondary" size="sm" icon="description" :href="\Illuminate\Support\Facades\URL::signedRoute('portal.test.scorecard', ['id' => $submission->id])" target="_blank">Bảng điểm Scorecard</x-ui.button>
                                        @can('placement_test.grade')
                                            <x-ui.button variant="secondary" size="sm" icon="assignment_turned_in" :href="route('placement-tests.results.show', $submission->id)">Chi tiết bài làm</x-ui.button>
                                        @endcan
                                    @endif
                                    @can('entrance_test.grade')
                                        @if (in_array($customer->stage, ['consulting', 'test_scheduled', 'testing', 'tested', 'result_sent'], true))
                                            <x-ui.button variant="secondary" size="sm" icon="edit_note" onclick="window.dispatchEvent(new CustomEvent('open-modal', { detail: 'crm-edit-test-score' }))">{{ $customer->stage === 'test_scheduled' ? 'Nhập điểm lần test lại' : 'Sửa điểm' }}</x-ui.button>
                                        @endif
                                    @endcan
                                </div>
                                <a href="{{ route('placement-tests.rubric-guide') }}" class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-primary hover:underline">
                                    Thang điểm &amp; hướng dẫn nhận xét<span class="material-symbols-outlined text-[16px]">arrow_forward</span>
                                </a>
                            </div>
                    </section>
                    @endif

                    {{-- Gửi kết quả & Phản hồi phụ huynh --}}
                    @if (! $hasResult && $resultLogs->isEmpty())
                        <div class="{{ $collapsedRow }}">
                            <span class="flex items-center gap-sm"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">forward_to_inbox</span>Gửi kết quả &amp; Phản hồi</span>
                            <span class="font-caption text-caption">Mở sau khi có kết quả test</span>
                        </div>
                    @else
                    <section class="space-y-md border-t border-surface-container-highest pt-lg">
                        <h4 class="flex items-center gap-sm font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-secondary">forward_to_inbox</span>Gửi kết quả &amp; Phản hồi</h4>
                        @foreach ($resultLogs->take(3) as $log)
                            <div class="rounded-lg bg-surface-container-low p-md font-body-small text-body-small">
                                <p class="whitespace-pre-line text-on-surface">{{ $log->content }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">{{ $log->user?->name ?? 'Hệ thống' }} · {{ $log->created_at->format('H:i d/m/Y') }}</p>
                            </div>
                        @endforeach
                        @can('lead.update')
                            @if ($hasResult && $customer->stage !== \App\Models\CrmCustomer::STAGE_LOST)
                                <form action="{{ route('crm.customers.notes.store', $customer->id) }}" method="POST" class="grid grid-cols-1 gap-md sm:grid-cols-3"
                                      x-data="{ dirty: false }" x-on:input="dirty = true" x-on:change="dirty = true">
                                    @csrf
                                    <input type="hidden" name="type" value="result">
                                    <x-ui.input type="datetime-local" name="sent_at" label="Ngày gửi KQ phụ huynh" required :value="old('sent_at', now()->format('Y-m-d\TH:i'))" max="{{ now()->format('Y-m-d\TH:i') }}" />
                                    <div class="sm:col-span-2">
                                        <x-ui.textarea name="content" label="Phản hồi của phụ huynh" rows="2" placeholder="Nhập ý kiến phản hồi của phụ huynh..." />
                                    </div>
                                    <div class="flex justify-end sm:col-span-3">
                                        {{-- Nút lưu trong khối để kiểu phụ, chỉ tô cam khi form đang được sửa --}}
                                        <x-ui.button type="submit" variant="secondary" size="sm" icon="forward_to_inbox" x-bind:class="dirty && '{{ $dirtySave }}'">Lưu gửi kết quả</x-ui.button>
                                    </div>
                                </form>
                            @elseif ($resultLogs->isEmpty())
                                <p class="font-body-small text-body-small text-on-surface-variant">Chưa có kết quả test để gửi phụ huynh.</p>
                            @endif
                        @endcan
                    </section>
                    @endif

                    {{-- Học thử: đặt / hủy buổi + nhận xét của GV ngay trong khối (A6 Q1: lưu theo khách) --}}
                    @if ($customer->trialBookings->isEmpty() && ! $canBookTrial)
                        <div class="{{ $collapsedRow }}">
                            <span class="flex items-center gap-sm"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">school</span>Học thử</span>
                            <span class="font-caption text-caption">{{ in_array($customer->stage, \App\Models\CrmCustomer::TRIAL_BOOKABLE_STAGES, true) ? 'Chưa có buổi học thử' : ($customer->stage === 'new' ? 'Mở khi khách sang Đang tư vấn' : 'Không đặt học thử ở giai đoạn '.$customer->stage_label) }}</span>
                        </div>
                    @else
                    <section class="space-y-md border-t border-surface-container-highest pt-lg">
                        <div class="flex flex-wrap items-center justify-between gap-sm">
                            <h4 class="flex items-center gap-sm font-h3 text-h3 text-on-surface">
                                <span class="material-symbols-outlined text-secondary">school</span>Học thử
                            </h4>
                            @if ($canBookTrial)
                                <div class="flex items-center gap-sm">
                                    <span class="font-caption text-caption text-on-surface-variant">Đã học thử {{ $trialState['used'] }}/{{ \App\Models\CrmTrialBooking::MAX_ACTIVE_PER_LEAD }} buổi</span>
                                    <x-ui.button variant="secondary" size="sm" icon="event_available" onclick="window.dispatchEvent(new CustomEvent('open-modal', { detail: 'crm-schedule-trial' }))">{{ $trialState['used'] > 0 ? 'Xếp buổi học thử '.($trialState['used'] + 1) : 'Xếp học thử' }}</x-ui.button>
                                </div>
                            @elseif ($trialState['exhausted'])
                                <span class="font-caption text-caption font-semibold text-on-surface-variant">Đã học thử đủ {{ \App\Models\CrmTrialBooking::MAX_ACTIVE_PER_LEAD }} buổi · khóa xếp học thử</span>
                            @endif
                        </div>
                        @forelse ($customer->trialBookings as $booking)
                            <div class="rounded-lg border border-surface-container-highest bg-surface-container-low p-md font-body-small text-body-small">
                                <div class="flex flex-wrap items-center justify-between gap-sm">
                                    <span class="font-semibold text-on-surface">Học thử · {{ $booking->classModel?->name }} · {{ $booking->session?->date?->format('d/m/Y') }} {{ $booking->session?->start_time?->format('H:i') }}</span>
                                    <x-ui.badge :color="$booking->status === 'attended' ? 'success' : ($booking->status === 'scheduled' ? 'info' : 'error')">{{ $booking->status_label }}</x-ui.badge>
                                </div>
                                <p class="mt-xs font-label text-label uppercase text-on-surface-variant">Nhận xét học thử</p>
                                @if ($booking->feedback || $booking->remarks)
                                    <p class="text-on-surface">{{ $booking->rating ? $booking->rating.'/5 · ' : '' }}{{ $booking->remarksSummary() !== '' ? $booking->remarksSummary().' · ' : '' }}{{ $booking->feedback }}</p>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ $booking->feedbackBy?->name }} · {{ $booking->feedback_at?->format('d/m/Y H:i') }}</p>
                                @else
                                    <p class="italic text-on-surface-variant">Chưa có nhận xét từ buổi học thử.</p>
                                @endif
                                @if ($booking->status === 'scheduled' && $stageControls['canCancelTrial'])
                                    <form action="{{ route('crm.customers.trial-bookings.cancel', [$customer->id, $booking->id]) }}" method="POST" class="mt-sm flex gap-xs">
                                        @csrf
                                        <div class="flex-1"><x-ui.input name="reason" id="cancel_reason_{{ $booking->id }}" required placeholder="Lý do hủy" aria-label="Lý do hủy" class="py-1 font-body-small text-body-small" /></div>
                                        <x-ui.button type="submit" variant="danger-text" size="sm">Hủy buổi</x-ui.button>
                                    </form>
                                @endif
                            </div>
                        @empty
                            <div class="rounded-lg bg-surface-container-low p-md font-body-small text-body-small">
                                <p class="font-label text-label uppercase text-on-surface-variant">Nhận xét học thử</p>
                                <p class="italic text-on-surface-variant">Chưa có nhận xét từ buổi học thử.</p>
                            </div>
                        @endforelse
                    </section>
                    @endif
                </div>

                <div x-show="tab === 'info'" x-cloak>
                    {{-- Thông tin hệ thống (không sửa) --}}
                    <dl class="flex flex-wrap gap-x-lg gap-y-xs border-b border-surface-container-highest px-lg py-sm font-body-small text-body-small text-on-surface-variant">
                        <div>Mã khách: <span class="font-code text-on-surface" title="{{ $customer->code }}">{{ $customer->short_code }}</span></div>
                        <div>Tạo hồ sơ: <span class="text-on-surface">{{ $customer->created_at->format('d/m/Y H:i') }}</span></div>
                        <div>Ngày chốt: <span class="text-on-surface">{{ $customer->converted_at?->format('d/m/Y H:i') ?? '—' }}</span></div>
                    </dl>
                    @if ($editForm)
                        {{-- Sửa trực tiếp mọi thông tin khách ngay trong hồ sơ (không còn trang / modal sửa riêng) --}}
                        @include('crm.customers._edit-form', $editForm)
                    @else
                    <div class="p-lg"><dl class="grid grid-cols-1 gap-md font-body-base text-body-base sm:grid-cols-2">
                        @foreach ([
                            'Mã khách hàng' => $customer->short_code,
                            'Email' => $customer->email ?? '—',
                            'Ngày sinh / Giới tính' => ($customer->dob?->format('d/m/Y') ?? '—').' ('.($customer->gender ?? 'Chưa rõ').')',
                            'Khóa quan tâm' => $customer->course_interest ?? 'Chưa chọn',
                            'Giá trị hợp đồng' => \App\Support\Money::format((float) $customer->deal_value),
                            'Địa chỉ' => $customer->address ?? 'Chưa cập nhật',
                            'Ngày tạo hồ sơ' => $customer->created_at->format('d/m/Y H:i'),
                            'Ngày chốt' => $customer->converted_at?->format('d/m/Y H:i') ?? '—',
                        ] as $label => $value)
                            <div class="rounded-lg bg-surface-container-low p-md">
                                <dt class="font-label text-label uppercase text-on-surface-variant">{{ $label }}</dt>
                                <dd class="mt-xs font-medium text-on-surface">{{ $value }}</dd>
                            </div>
                        @endforeach
                        @if ($customer->notes)
                            <div class="rounded-lg bg-surface-container-low p-md sm:col-span-2">
                                <dt class="font-label text-label uppercase text-on-surface-variant">Ghi chú nhu cầu</dt>
                                <dd class="mt-xs whitespace-pre-line text-on-surface">{{ $customer->notes }}</dd>
                            </div>
                        @endif
                    </dl></div>
                    @endif
                </div>
            </div>

            {{-- Lịch sử hoạt động --}}
            <div class="{{ $card }}" id="timeline">
                <div class="flex flex-wrap items-center justify-between gap-md border-b border-surface-container-highest p-lg">
                    <h3 class="font-h3 text-h3 text-on-surface">Lịch sử hoạt động <span class="font-body-small text-body-small text-on-surface-variant">({{ $histories->count() }}{{ $logType ? '/'.$customer->histories->count() : '' }})</span></h3>
                    <form method="GET" action="{{ route('crm.customers.show', $customer->id) }}#timeline">
                        <x-ui.select name="log_type" onchange="this.form.submit()" aria-label="Lọc lịch sử" placeholder="Tất cả hoạt động" class="py-xs font-body-small text-body-small">
                            @foreach (\App\Models\CrmCustomerHistory::FILTER_TYPES as $typeKey => $typeLabel)
                                @php $typeCount = $customer->histories->where('type', $typeKey)->count(); @endphp
                                @if ($typeCount > 0 || $logType === $typeKey)
                                    <option value="{{ $typeKey }}" @selected($logType === $typeKey)>{{ $typeLabel }} ({{ $typeCount }})</option>
                                @endif
                            @endforeach
                        </x-ui.select>
                    </form>
                </div>

                @can('lead.update')
                    <form action="{{ route('crm.customers.notes.store', $customer->id) }}" method="POST" class="border-b border-surface-container-highest bg-surface-container-low/40 p-lg" x-data="{ noteType: 'call', dirty: false }" x-on:input="dirty = true">
                        @csrf
                        <input type="hidden" name="type" :value="noteType" />
                        <div class="flex flex-col gap-md sm:flex-row sm:items-end">
                            <div class="flex-1 space-y-sm">
                                <x-ui.textarea name="content" id="history_note_content" rows="2" required placeholder="Ghi chú nội dung liên hệ mới..." aria-label="Ghi chú nội dung liên hệ" />
                                <div class="flex flex-wrap items-center gap-sm">
                                    <span class="font-body-small text-body-small text-on-surface-variant">Hình thức:</span>
                                    @foreach (['call' => 'Gọi điện', 'message' => 'Zalo/SMS', 'meet' => 'Trực tiếp', 'test' => 'Test đầu vào', 'note' => 'Ghi chú'] as $noteKey => $noteLabel)
                                        <button type="button" @click="noteType = @js($noteKey)"
                                                :class="noteType === @js($noteKey) ? 'bg-secondary text-white border-secondary' : 'bg-surface-container-lowest text-on-surface-variant border-outline-variant hover:bg-surface-container-high'"
                                                class="rounded-full border px-md py-xs font-body-small text-body-small transition-colors">{{ $noteLabel }}</button>
                                    @endforeach
                                </div>
                            </div>
                            <x-ui.button type="submit" variant="secondary" icon="send" x-bind:class="dirty && '{{ $dirtySave }}'">Lưu ghi chú</x-ui.button>
                        </div>
                    </form>
                @endcan

                <div class="space-y-lg p-lg">
                    @forelse ($histories as $history)
                        @php
                            $isLost = $history->type === 'stage_change' && $history->to_stage === \App\Models\CrmCustomer::STAGE_LOST;
                            $iconTone = match (true) {
                                $isLost => 'bg-error-container text-error',
                                in_array($history->type, ['call', 'message', 'meet'], true) => 'bg-secondary-fixed text-secondary',
                                in_array($history->type, ['test', 'result', 'trial'], true) => 'bg-tertiary-fixed/50 text-tertiary',
                                $history->type === 'assign' => 'bg-primary-fixed text-primary',
                                default => 'bg-surface-container-high text-on-surface-variant',
                            };
                        @endphp
                        <div class="flex items-start gap-md">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full {{ $iconTone }}">
                                <span class="material-symbols-outlined text-[18px]" style="font-variation-settings: 'FILL' 1;">{{ $isLost ? 'person_off' : $history->type_icon }}</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center justify-between gap-sm">
                                    <span class="font-body-semibold text-body-semibold text-on-surface">{{ $history->user?->name ?? 'Hệ thống' }}</span>
                                    <span class="font-code text-caption text-on-surface-variant">{{ $history->created_at->format('H:i - d/m/Y') }}</span>
                                </div>
                                @if ($isLost)
                                    <div class="mt-xs rounded-lg border-l-4 border-error bg-error-container/30 p-sm">
                                        <span class="font-label text-label uppercase text-error">Lý do thất bại</span>
                                        <p class="font-body-base text-body-base text-on-surface">{{ $history->reason ?: $history->content }}</p>
                                    </div>
                                @else
                                    <p class="mt-xs whitespace-pre-line font-body-base text-body-base text-on-surface-variant">{{ $history->content }}</p>
                                @endif
                                @if (isset(\App\Models\CrmCustomerHistory::FILTER_TYPES[$history->type]))
                                    <span class="mt-xs inline-block rounded bg-surface-container-high px-sm py-0.5 font-caption text-caption text-on-surface-variant">{{ \App\Models\CrmCustomerHistory::FILTER_TYPES[$history->type] }}</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <x-ui.empty-state icon="history" title="Chưa có lịch sử hoạt động" />
                    @endforelse
                    <div class="flex items-center gap-sm pt-sm">
                        <span class="h-px flex-1 bg-surface-container-highest"></span>
                        <span class="font-caption text-caption text-on-surface-variant">Bắt đầu tạo hồ sơ - {{ $customer->created_at->format('d/m/Y') }}</span>
                        <span class="h-px flex-1 bg-surface-container-highest"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

</x-app-layout>
