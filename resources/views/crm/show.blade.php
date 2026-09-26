<x-app-layout :title="'Chi tiết khách — '.$customer->name">
    @php
        $sub = $latestSubmission ?? $customer->latestSubmission ?? $customer->submissions->first();
        $hasTested = $sub || ! empty($customer->test_score) || in_array($customer->stage, ['tested', 'won'], true);
        $words = preg_split('/\s+/u', trim($customer->name)) ?: ['?'];
        $initials = mb_strtoupper(mb_substr($words[0], 0, 1).(count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    @endphp

    {{-- Mockup crm-ui-mockup/chi-tiet-khach-hang: tiêu đề + Thất bại / In hồ sơ / Phân công lại --}}
    <x-ui.page-header title="Chi tiết Khách hàng" description="Quản lý thông tin học viên và lịch sử tương tác hệ thống">
        <x-slot:breadcrumbs>
            <a href="{{ route('crm.customers.index') }}" class="hover:text-primary">Khách hàng</a>
            <span class="material-symbols-outlined text-[14px]">chevron_right</span>
            <span class="font-code">{{ $customer->code }}</span>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            @if ($stageControls['canLose'])
                <x-ui.button variant="danger-text" icon="person_off" class="!border-error" onclick="window.dispatchEvent(new CustomEvent('open-modal', { detail: 'crm-mark-lost' }))"><span>Thất bại</span></x-ui.button>
            @endif
            <x-ui.button variant="secondary" icon="print" :href="route('crm.customers.print', $customer->id)" target="_blank">In hồ sơ</x-ui.button>
            @if ($canReassign)
                <x-ui.button icon="person_add" x-data x-on:click="$dispatch('open-modal', 'reassign-customer')">Phân công lại</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Thao tác theo giai đoạn (A6): tiến 1 bước, lùi (Admin), chốt, gán lớp, học thử, sửa, xóa --}}
    <div class="mb-lg flex flex-wrap items-center gap-sm">
        @if ($stageControls['next'])
            <form action="{{ route('crm.customers.stage', $customer->id) }}" method="POST" class="inline">
                @csrf
                <input type="hidden" name="stage" value="{{ $stageControls['next'] }}" />
                <x-ui.button type="submit" variant="secondary" size="sm" icon="arrow_forward" title="Chuyển tiến 1 bước">Sang bước: {{ \App\Models\CrmCustomer::stageLabel($stageControls['next']) }}</x-ui.button>
            </form>
        @endif
        @if ($stageControls['backward'])
            <x-ui.button variant="danger-text" size="sm" icon="undo" onclick="window.dispatchEvent(new CustomEvent('open-modal', { detail: 'crm-stage-backward' }))">Lùi giai đoạn</x-ui.button>
        @endif
        @can('lead.convert')
            @if (in_array($customer->stage, \App\Models\CrmCustomer::CLOSABLE_STAGES, true) && ! $customer->converted_student_id)
                <x-ui.button size="sm" icon="how_to_reg" :href="route('crm.closing-wizard', ['customer_id' => $customer->id])">Chốt &amp; Xếp lớp</x-ui.button>
            @endif
        @endcan
        @if ($customer->stage === 'waiting_class')
            @can('student.assign_class')
                <x-ui.button size="sm" icon="assignment_turned_in" :href="route('crm.waiting-list')">Gán lớp</x-ui.button>
            @endcan
        @endif
        @if ($canBookTrial)
            <x-ui.button variant="secondary" size="sm" icon="school" onclick="window.dispatchEvent(new CustomEvent('open-modal', { detail: 'crm-schedule-trial' }))">Đặt học thử</x-ui.button>
        @endif
        @can('lead.delete')
            @if (! in_array($customer->stage, ['won', 'lost'], true) && ! $customer->converted_student_id)
                <form action="{{ route('crm.customers.destroy', $customer->id) }}" method="POST" class="inline" data-confirm="Bạn có chắc chắn muốn xóa khách {{ $customer->name }} ({{ $customer->code }})?">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa khách" aria-label="Xóa khách" />
                </form>
            @endif
        @endcan
    </div>

    @if ($canReassign)
        <x-ui.modal name="reassign-customer" title="Phân công lại Sales phụ trách" max-width="md" :show="$errors->has('reason') || $errors->has('assigned_user_id')">
            <form id="reassign-form" action="{{ route('crm.customers.reassign', $customer->id) }}" method="POST" class="space-y-3">
                @csrf
                <p class="text-body-small text-on-surface-variant">Hiện tại: <strong>{{ $customer->assignedUser?->name ?? 'Chưa phân công' }}</strong>. Thay đổi được ghi vào lịch sử khách.</p>
                <x-ui.select name="assigned_user_id" label="Sales phụ trách mới" required placeholder="-- Chọn người phụ trách --"
                    :options="$reassignUsers->reject(fn ($u) => $u->id === $customer->assigned_user_id)->mapWithKeys(fn ($u) => [$u->id => $u->name.' ('.$u->email.')'])" />
                <x-ui.textarea name="reason" label="Lý do phân công lại" required rows="3" placeholder="VD: Sales cũ nghỉ phép, chuyển khách cho cơ sở khác..." />
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
            <x-ui.textarea name="lost_reason" rows="4" required placeholder="Ví dụ: chưa phù hợp học phí, lịch học, không liên hệ được..." aria-label="Lý do thất bại" />
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
            <x-ui.textarea name="reason" rows="3" required placeholder="Lý do lùi giai đoạn (bắt buộc)" aria-label="Lý do lùi giai đoạn" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'crm-stage-backward')">Hủy</x-ui.button>
            <x-ui.button type="submit" form="stage-backward-form" variant="danger">Lùi giai đoạn</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
    @endif

    @if ($canBookTrial)
    {{-- Học thử: hoạt động trong giai đoạn tư vấn (không đổi stage) --}}
    <x-ui.modal name="crm-schedule-trial" id="scheduleTrialModal" title="Đặt lịch học thử" max-width="lg">
        <p class="mb-2 text-xs text-on-surface-variant">Chọn 1–2 buổi học thật của lớp cùng trình độ tại {{ $customer->branch?->name ?? 'chi nhánh của khách' }}. Giáo viên của buổi sẽ thấy khách trong trang "Nhận xét học thử" và nhận xét như học sinh chính thức.</p>
        <p class="text-xs mb-4 {{ $trialRemaining > 0 ? 'text-secondary' : 'text-error' }} font-semibold">Còn đặt được {{ $trialRemaining }}/{{ \App\Models\CrmTrialBooking::MAX_ACTIVE_PER_LEAD }} buổi học thử.@if ($latestSubmission?->finalClass()) Trình độ theo test: {{ $latestSubmission->finalClass() }}.@endif</p>
        <form id="schedule-trial-form" action="{{ route('crm.customers.trial-bookings.store', $customer->id) }}" method="POST" class="space-y-3 text-xs">
            @csrf
            <div class="max-h-64 overflow-y-auto border border-surface-container-highest rounded-xl divide-y divide-surface-container-highest">
                @forelse ($trialSessions as $session)
                    <label class="flex items-start gap-2 p-2.5 hover:bg-secondary/5 cursor-pointer">
                        <input type="checkbox" name="class_session_ids[]" value="{{ $session->id }}" class="mt-0.5 rounded border-outline-variant text-secondary" />
                        <span>
                            <span class="font-bold text-on-surface">{{ $session->classModel?->name }}</span>
                            @if ($session->matches_level)<x-ui.badge color="success" :dot="false" class="ml-1 font-bold !text-[10px]">Khớp trình độ</x-ui.badge>@endif
                            <span class="text-on-surface-variant">· {{ $session->classModel?->course?->name ?? 'Chưa gán khóa' }}{{ $session->classModel?->level ? ' · '.$session->classModel->level : '' }}</span>
                            <span class="block text-on-surface-variant">{{ $session->date->format('d/m/Y') }} · {{ $session->start_time?->format('H:i') }}–{{ $session->end_time?->format('H:i') }} · GV: {{ $session->teacher?->name ?? 'Chưa gán' }}</span>
                        </span>
                    </label>
                @empty
                    <div class="p-4 text-center text-on-surface-variant/70">Chưa có buổi học sắp tới phù hợp.</div>
                @endforelse
            </div>
            <x-ui.textarea name="notes" rows="2" placeholder="Ghi chú cho giáo viên (trình độ, mục tiêu...)" aria-label="Ghi chú cho giáo viên" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'crm-schedule-trial')">Hủy</x-ui.button>
            <x-ui.button type="submit" form="schedule-trial-form">Lưu lịch học thử</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
    @endif

    {{-- Schedule Test Modal --}}
    <x-ui.modal name="crm-schedule-test" id="scheduleTestModal" title="Hẹn lịch test đầu vào" max-width="md">
        <form id="schedule-test-form" action="{{ route('crm.customers.schedule-test', $customer->id) }}" method="POST" class="space-y-3 text-xs">
            @csrf
            <div class="grid grid-cols-2 gap-3">
                <x-ui.date name="appointment_date" id="modal_appointment_date" label="Ngày hẹn test" :value="date('Y-m-d')" required />
                <x-ui.input type="time" name="appointment_time" id="modal_appointment_time" label="Giờ hẹn" value="14:00" required />
            </div>

            <x-ui.select name="appointment_type" id="modal_appointment_type" label="Hình thức làm bài" value="" required class="font-bold !text-primary">
                <option value="online">Trực tuyến (Online qua link Portal)</option>
                <option value="offline">Tại cơ sở (Offline tại trung tâm)</option>
            </x-ui.select>

            <x-ui.select name="assigned_test_id" id="modal_assigned_test_id" label="Đề test gán cho khách" value="">
                @foreach ($placementTests ?? [] as $test)
                    <option value="{{ $test->id }}">{{ $test->title }} ({{ $test->code }})</option>
                @endforeach
            </x-ui.select>

            <x-ui.select name="examiner_id" id="modal_examiner_id" label="Giáo viên / Giám thị phụ trách chấm" value="" placeholder="-- Tự động chấm AI / Chưa gán --">
                @foreach ($examiners ?? [] as $examiner)
                    <option value="{{ $examiner->id }}">{{ $examiner->name }}</option>
                @endforeach
            </x-ui.select>

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
                <x-ui.alert type="warning" class="text-[11px]">
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
    @endphp

    <div class="grid grid-cols-1 gap-lg lg:grid-cols-12">
        {{-- ── Cột trái: thông tin, trạng thái & hạn xử lý, chăm sóc tháng đầu ── --}}
        <div class="space-y-lg lg:col-span-4">
            <div class="{{ $card }} p-lg">
                <div class="mb-lg flex items-center gap-md">
                    <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-primary-fixed font-h2 text-h2 text-on-primary-fixed">{{ $initials }}</div>
                    <div class="min-w-0">
                        <h2 class="font-h2 text-h2 text-on-surface">{{ $customer->name }}</h2>
                        <div class="mt-xs flex flex-wrap items-center gap-xs">
                            <span class="inline-flex items-center rounded-full border px-sm py-0.5 font-caption text-caption font-bold {{ $customer->stage_badge }}">{{ $customer->stage_label }}</span>
                            @if ($hasTested)
                                <span class="inline-flex items-center gap-xs rounded-full bg-tertiary/10 px-sm py-0.5 font-caption text-caption font-bold text-tertiary">
                                    <span class="material-symbols-outlined text-[14px]">task_alt</span>Đã làm bài test ({{ $sub?->scoreSummary() ?? $customer->test_score }})
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
                        <a href="{{ route('crm.customers.show', ['id' => $customer->id, 'tab' => 'info']) }}#next_follow_up_at" class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-primary hover:underline">
                            <span class="material-symbols-outlined text-[16px]">event</span>Đặt hạn liên hệ
                        </a>
                    @endcan
                </div>
            </div>

            {{-- Chăm sóc tháng đầu: chỉ khi đã chuyển đổi (có hồ sơ học viên) --}}
            @if ($customer->converted_student_id)
                @php $careState = $customer->care_checklist ?? []; @endphp
                <form action="{{ route('crm.customers.care-checklist', $customer->id) }}" method="POST" class="{{ $card }} space-y-md p-lg">
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
                            <x-ui.button type="submit" size="sm" icon="save">Lưu checklist</x-ui.button>
                        </div>
                    @endcan
                </form>
            @endif
        </div>

        {{-- ── Cột phải: thao tác (tab) + lịch sử hoạt động ── --}}
        <div class="space-y-lg lg:col-span-8">
            <div class="{{ $card }} overflow-hidden" x-data="{ tab: @js(old('_tab', request('tab')) === 'info' ? 'info' : 'ops') }">
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

                        @if (! $hasResult && ! $hasScheduled)
                            @can('entrance_test.send')
                                <form action="{{ route('crm.customers.schedule-test', $customer->id) }}" method="POST" class="space-y-md">
                                    @csrf
                                    <input type="hidden" name="appointment_type" value="online" />
                                    @php
                                        $testGroups = $placementTests->mapWithKeys(fn ($t) => [$t->id => \App\Services\PlacementRubricService::detectGradeGroup($t->code)]);
                                        $levelOptions = collect(\App\Services\PlacementRubricService::gradeGroups())->only($testGroups->unique()->values()->all());
                                    @endphp
                                    {{-- Mockup: "Chọn cấp độ" → "Danh sách đề tương ứng" --}}
                                    <div class="grid grid-cols-1 gap-md sm:grid-cols-2" x-data="{ level: '', groups: @js($testGroups), testId: @js((string) old('assigned_test_id', $placementTests->first()?->id)) }">
                                        <x-ui.field label="Chọn cấp độ" for="test_level">
                                            <x-ui.select id="test_level" x-model="level" x-on:change="const first = Object.keys(groups).find((id) => !level || groups[id] === level); if (first) testId = first">
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
                            <p class="font-body-small text-body-small text-on-surface-variant">Cấp độ: <strong class="text-on-surface">{{ \App\Services\PlacementRubricService::groupLabel(\App\Services\PlacementRubricService::detectGradeGroup($customer->assignedTest?->code)) }}</strong></p>
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

                    {{-- Gửi kết quả & Phản hồi phụ huynh --}}
                    <section class="space-y-md border-t border-surface-container-highest pt-lg">
                        <h4 class="font-h3 text-h3 text-on-surface">Gửi kết quả &amp; Phản hồi</h4>
                        @foreach ($resultLogs->take(3) as $log)
                            <div class="rounded-lg bg-surface-container-low p-md font-body-small text-body-small">
                                <p class="whitespace-pre-line text-on-surface">{{ $log->content }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">{{ $log->user?->name ?? 'Hệ thống' }} · {{ $log->created_at->format('H:i d/m/Y') }}</p>
                            </div>
                        @endforeach
                        @can('lead.update')
                            @if ($hasResult && $customer->stage !== \App\Models\CrmCustomer::STAGE_LOST)
                                <form action="{{ route('crm.customers.notes.store', $customer->id) }}" method="POST" class="grid grid-cols-1 gap-md sm:grid-cols-3">
                                    @csrf
                                    <input type="hidden" name="type" value="result">
                                    <x-ui.input type="datetime-local" name="sent_at" label="Ngày gửi KQ phụ huynh" required :value="old('sent_at', now()->format('Y-m-d\TH:i'))" max="{{ now()->format('Y-m-d\TH:i') }}" />
                                    <div class="sm:col-span-2">
                                        <x-ui.textarea name="content" label="Phản hồi của phụ huynh" rows="2" placeholder="Nhập ý kiến phản hồi của phụ huynh..." />
                                    </div>
                                    <div class="flex justify-end sm:col-span-3">
                                        <x-ui.button type="submit" size="sm" icon="forward_to_inbox">Lưu gửi kết quả</x-ui.button>
                                    </div>
                                </form>
                            @elseif ($resultLogs->isEmpty())
                                <p class="font-body-small text-body-small text-on-surface-variant">Chưa có kết quả test để gửi phụ huynh.</p>
                            @endif
                        @endcan
                    </section>

                    {{-- Kết quả & Đánh giá (thang điểm khối lớp — A6 Q2) --}}
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
                        @if ($hasResult)
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
                                        @if (! in_array($customer->stage, ['won', 'lost'], true))
                                            <x-ui.button variant="secondary" size="sm" icon="edit_note" onclick="window.dispatchEvent(new CustomEvent('open-modal', { detail: 'crm-edit-test-score' }))">{{ $customer->stage === 'test_scheduled' ? 'Nhập điểm lần test lại' : 'Sửa điểm' }}</x-ui.button>
                                        @endif
                                    @endcan
                                </div>
                                <a href="{{ route('placement-tests.rubric-guide') }}" class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-primary hover:underline">
                                    Thang điểm &amp; hướng dẫn nhận xét<span class="material-symbols-outlined text-[16px]">arrow_forward</span>
                                </a>
                            </div>
                        @else
                            <p class="font-body-small text-body-small text-on-surface-variant">Chưa có kết quả test đầu vào.</p>
                        @endif
                    </section>

                    {{-- Nhận xét học thử (A6 Q1: lưu theo khách) --}}
                    <section class="space-y-md border-t border-surface-container-highest pt-lg">
                        <h4 class="flex items-center gap-sm font-h3 text-h3 text-on-surface">
                            <span class="material-symbols-outlined text-secondary">comment</span>Nhận xét học thử
                        </h4>
                        @forelse ($customer->trialBookings as $booking)
                            <div class="rounded-lg border border-surface-container-highest bg-surface-container-low p-md font-body-small text-body-small">
                                <div class="flex flex-wrap items-center justify-between gap-sm">
                                    <span class="font-semibold text-on-surface">Học thử · {{ $booking->classModel?->name }} · {{ $booking->session?->date?->format('d/m/Y') }} {{ $booking->session?->start_time?->format('H:i') }}</span>
                                    <x-ui.badge :color="$booking->status === 'attended' ? 'success' : ($booking->status === 'scheduled' ? 'info' : 'error')">{{ $booking->status_label }}</x-ui.badge>
                                </div>
                                <p class="mt-xs font-label text-label uppercase text-on-surface-variant">Ghi chú buổi học</p>
                                @if ($booking->feedback || $booking->remarks)
                                    <p class="text-on-surface">{{ $booking->rating ? $booking->rating.'/5 · ' : '' }}{{ $booking->remarksSummary() !== '' ? $booking->remarksSummary().' · ' : '' }}{{ $booking->feedback }}</p>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ $booking->feedbackBy?->name }} · {{ $booking->feedback_at?->format('d/m/Y H:i') }}</p>
                                @else
                                    <p class="italic text-on-surface-variant">Chưa có nhận xét từ buổi học thử.</p>
                                @endif
                                @if ($booking->status === 'scheduled' && $canBookTrial)
                                    <form action="{{ route('crm.customers.trial-bookings.cancel', [$customer->id, $booking->id]) }}" method="POST" class="mt-sm flex gap-xs">
                                        @csrf
                                        <div class="flex-1"><x-ui.input name="reason" id="cancel_reason_{{ $booking->id }}" required placeholder="Lý do hủy" aria-label="Lý do hủy" class="py-1 font-body-small text-body-small" /></div>
                                        <x-ui.button type="submit" variant="danger-text" size="sm">Hủy buổi</x-ui.button>
                                    </form>
                                @endif
                            </div>
                        @empty
                            <div class="rounded-lg bg-surface-container-low p-md font-body-small text-body-small">
                                <p class="font-label text-label uppercase text-on-surface-variant">Ghi chú buổi học</p>
                                <p class="italic text-on-surface-variant">Chưa có nhận xét từ buổi học thử.</p>
                            </div>
                        @endforelse
                    </section>
                </div>

                <div x-show="tab === 'info'" x-cloak>
                    {{-- Thông tin hệ thống (không sửa) --}}
                    <dl class="flex flex-wrap gap-x-lg gap-y-xs border-b border-surface-container-highest px-lg py-sm font-body-small text-body-small text-on-surface-variant">
                        <div>Mã khách: <span class="font-code text-on-surface">{{ $customer->code }}</span></div>
                        <div>Tạo hồ sơ: <span class="text-on-surface">{{ $customer->created_at->format('d/m/Y H:i') }}</span></div>
                        <div>Ngày chốt: <span class="text-on-surface">{{ $customer->converted_at?->format('d/m/Y H:i') ?? '—' }}</span></div>
                    </dl>
                    @if ($editForm)
                        {{-- Sửa trực tiếp mọi thông tin khách ngay trong hồ sơ (không còn trang / modal sửa riêng) --}}
                        @include('crm.customers._edit-form', $editForm)
                    @else
                    <div class="p-lg"><dl class="grid grid-cols-1 gap-md font-body-base text-body-base sm:grid-cols-2">
                        @foreach ([
                            'Mã khách hàng' => $customer->code,
                            'Email' => $customer->email ?? '—',
                            'Ngày sinh / Giới tính' => ($customer->dob?->format('d/m/Y') ?? '—').' ('.($customer->gender ?? 'Chưa rõ').')',
                            'Khóa quan tâm' => $customer->course_interest ?? 'Chưa chọn',
                            'Giá trị hợp đồng' => number_format((float) $customer->deal_value, 0, ',', '.').' ₫',
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
                    <form action="{{ route('crm.customers.notes.store', $customer->id) }}" method="POST" class="border-b border-surface-container-highest bg-surface-container-low/40 p-lg" x-data="{ noteType: 'call' }">
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
                            <x-ui.button type="submit" icon="send">Lưu ghi chú</x-ui.button>
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

    @push('scripts')
    <script>
        // Confirm xoá khách qua data-confirm (thay cho inline onsubmit — tránh XSS qua tên khách)
        document.addEventListener('submit', function (event) {
            const form = event.target instanceof Element ? event.target.closest('form[data-confirm]') : null;
            if (form && !window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        }, true);
    </script>
    @endpush
</x-app-layout>
