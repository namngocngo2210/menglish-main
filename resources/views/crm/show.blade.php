<x-app-layout>
    <x-slot name="header">
        @php
            $sub = $latestSubmission ?? $customer->latestSubmission ?? $customer->submissions->first();
            $hasTested = $sub || !empty($customer->test_score) || in_array($customer->stage, ['tested', 'won']);
        @endphp

        <div class="flex items-center justify-between flex-wrap gap-3">
            <!-- Left Info -->
            <div class="flex items-center gap-3">
                <a href="{{ route('crm.customers.index') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="text-lg sm:text-xl font-black text-gray-900 tracking-tight">{{ $customer->name }}</h1>
                        <span class="text-xs px-2.5 py-0.5 rounded-full border font-bold {{ $customer->stage_badge }}">{{ $customer->stage_label }}</span>
                    </div>
                    <p class="text-xs text-gray-500 font-mono mt-0.5">
                        <strong class="text-gray-700 font-semibold">{{ $customer->code }}</strong> · {{ $customer->phone }} · {{ $customer->branch?->name ?? 'Chưa gán cơ sở' }}
                    </p>
                </div>
            </div>

            <!-- Right Actions -->
            <div class="flex items-center gap-2 shrink-0 flex-wrap">
                @if ($hasTested)
                    <!-- Nút ĐÃ LÀM BÀI TEST -->
                    <a href="{{ $sub ? \Illuminate\Support\Facades\URL::signedRoute('portal.test.scorecard', ['id' => $sub->id]) : route('placement-tests.index') }}" target="_blank" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-xs transition whitespace-nowrap shrink-0">
                        <span class="material-symbols-outlined text-[16px] text-emerald-200">task_alt</span>
                        <span>Đã Làm Bài Test ({{ $sub?->scoreSummary() ?? $customer->test_score }})</span>
                    </a>

                    @can('entrance_test.grade')
                    @if (! in_array($customer->stage, ['won', 'lost'], true))
                    <button type="button" onclick="document.getElementById('editTestScoreModal').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-orange-50 hover:bg-orange-100 text-primary-container border border-orange-200 text-xs font-bold shadow-2xs transition whitespace-nowrap shrink-0 cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]">edit_note</span>
                        <span>{{ $customer->stage === 'test_scheduled' ? 'Nhập điểm lần test lại' : 'Sửa Điểm Test' }}</span>
                    </button>
                    @endif
                    @endcan

                    @can('entrance_test.send')
                    @if (in_array($customer->stage, ['consulting', 'test_scheduled', 'tested'], true))
                    <button type="button" onclick="document.getElementById('scheduleTestModal').classList.remove('hidden')" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium transition whitespace-nowrap shrink-0 cursor-pointer" title="Hẹn lịch thi lại nếu cần">
                        <span class="material-symbols-outlined text-[15px]">event_repeat</span>
                        <span>Hẹn test lại</span>
                    </button>
                    @endif
                    @endcan
                @else
                    <!-- Nút HẸN LỊCH TEST khi chưa làm bài -->
                    @can('entrance_test.send')
                    @if ($customer->stage === 'consulting')
                    <button type="button" onclick="document.getElementById('scheduleTestModal').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-xs transition whitespace-nowrap shrink-0 cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]">calendar_add_on</span>
                        <span>Hẹn Lịch Test</span>
                    </button>
                    @endif
                    @endcan

                    @can('entrance_test.grade')
                    @if (in_array($customer->stage, ['consulting', 'test_scheduled'], true))
                    <button type="button" onclick="document.getElementById('editTestScoreModal').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-orange-50 hover:bg-orange-100 text-primary-container border border-orange-200 text-xs font-bold shadow-2xs transition whitespace-nowrap shrink-0 cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]">post_add</span>
                        <span>Nhập Điểm Test</span>
                    </button>
                    @endif
                    @endcan
                @endif

                @if ($canBookTrial)
                <button type="button" onclick="document.getElementById('scheduleTrialModal').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-fuchsia-50 text-fuchsia-700 border border-fuchsia-200 text-xs font-bold">
                    <span class="material-symbols-outlined text-[16px]">school</span><span>Đặt học thử</span>
                </button>
                @endif

                <a href="{{ route('crm.customers.print', $customer->id) }}" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-xs font-semibold text-gray-700 transition whitespace-nowrap shrink-0 shadow-2xs">
                    <span class="material-symbols-outlined text-[16px]">print</span>
                    <span>In hồ sơ</span>
                </a>

                @if ($canReassign)
                <button type="button" x-data @click="$dispatch('open-modal', 'reassign-customer')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-indigo-200 bg-indigo-50 hover:bg-indigo-100 text-xs font-semibold text-indigo-700 transition whitespace-nowrap shrink-0 shadow-2xs">
                    <span class="material-symbols-outlined text-[16px]">assignment_ind</span>
                    <span>Phân công lại</span>
                </button>
                @endif

                @can('lead.update')
                <a href="{{ route('crm.customers.edit', $customer->id) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-xs font-semibold text-gray-700 transition whitespace-nowrap shrink-0 shadow-2xs">
                    <span class="material-symbols-outlined text-[16px]">edit</span>
                    <span>Sửa thông tin</span>
                </a>
                @endcan

                @can('lead.convert')
                @if (in_array($customer->stage, \App\Models\CrmCustomer::CLOSABLE_STAGES, true) && ! $customer->converted_student_id)
                <a href="{{ route('crm.closing-wizard', ['customer_id' => $customer->id]) }}" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold shadow-sm transition whitespace-nowrap shrink-0">
                    <span class="material-symbols-outlined text-[16px] text-amber-400">route</span>
                    <span>Chốt &amp; Xếp lớp</span>
                </a>
                @endif
                @endcan

                @if ($customer->stage === 'waiting_class')
                @can('student.assign_class')
                <a href="{{ route('crm.customers.won') }}#waiting-class" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-yellow-50 text-yellow-800 border border-yellow-200 text-xs font-bold">
                    <span class="material-symbols-outlined text-[16px]">assignment_turned_in</span><span>Gán lớp</span>
                </a>
                @endcan
                @endif

                @if ($stageControls['next'])
                <form action="{{ route('crm.customers.stage', $customer->id) }}" method="POST" class="inline shrink-0">
                    @csrf
                    <input type="hidden" name="stage" value="{{ $stageControls['next'] }}" />
                    <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 text-xs font-bold cursor-pointer" title="Chuyển tiến 1 bước">
                        <span>{{ \App\Models\CrmCustomer::stageLabel($stageControls['next']) }}</span>
                        <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
                    </button>
                </form>
                @endif

                @if ($stageControls['backward'])
                <button type="button" onclick="document.getElementById('stageBackwardModal').classList.remove('hidden')" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-white text-rose-700 border border-rose-200 text-xs font-bold">
                    <span class="material-symbols-outlined text-[16px]">undo</span><span>Lùi giai đoạn</span>
                </button>
                @endif

                @if ($stageControls['canLose'])
                    <button type="button" onclick="document.getElementById('markLostModal').classList.remove('hidden')" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-rose-50 text-rose-700 border border-rose-200 text-xs font-bold">
                        <span class="material-symbols-outlined text-[16px]">cancel</span><span>Thất bại</span>
                    </button>
                @endif

                @can('lead.delete')
                @if (! in_array($customer->stage, ['won', 'lost'], true) && !$customer->converted_student_id)
                <form action="{{ route('crm.customers.destroy', $customer->id) }}" method="POST" class="inline shrink-0" data-confirm="Bạn có chắc chắn muốn xóa lead {{ $customer->name }} ({{ $customer->code }})? Thao tác này không thể hoàn tác.">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center gap-1 p-1.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 text-xs font-semibold shadow-2xs transition cursor-pointer" title="Xóa Lead">
                        <span class="material-symbols-outlined text-[16px]">delete</span>
                    </button>
                </form>
                @endif
                @endcan
            </div>
        </div>
    </x-slot>

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
                <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'reassign-customer')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="reassign-form" icon="assignment_ind">Phân công lại</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <div id="markLostModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl">
            <h3 class="font-bold text-sm mb-4">Ghi nhận lý do thất bại</h3>
            <p class="text-xs text-gray-500 mb-3">Lead thất bại được lưu để đối soát và không mở lại.</p>
            <form action="{{ route('crm.customers.stage', $customer->id) }}" method="POST" class="space-y-3 text-xs">
                @csrf
                <input type="hidden" name="stage" value="lost" />
                <textarea name="lost_reason" rows="4" required placeholder="Ví dụ: chưa phù hợp học phí, lịch học, không liên hệ được..." class="w-full rounded-xl border-gray-200 text-xs"></textarea>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('markLostModal').classList.add('hidden')" class="px-3 py-2 border rounded-xl">Hủy</button>
                    <button type="submit" class="px-4 py-2 bg-rose-600 text-white font-bold rounded-xl">Xác nhận</button>
                </div>
            </form>
        </div>
    </div>

    @if ($stageControls['backward'])
    <div id="stageBackwardModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl">
            <h3 class="font-bold text-sm mb-2">Lùi giai đoạn Lead</h3>
            <p class="text-xs text-gray-500 mb-3">Chỉ Admin được lùi giai đoạn; lý do được lưu vào lịch sử.</p>
            <form action="{{ route('crm.customers.stage', $customer->id) }}" method="POST" class="space-y-3 text-xs">
                @csrf
                <select name="stage" required class="w-full rounded-xl border-gray-200 text-xs">
                    @foreach (array_reverse($stageControls['backward']) as $target)
                        <option value="{{ $target }}">{{ \App\Models\CrmCustomer::stageLabel($target) }}</option>
                    @endforeach
                </select>
                <textarea name="reason" rows="3" required placeholder="Lý do lùi giai đoạn (bắt buộc)" class="w-full rounded-xl border-gray-200 text-xs"></textarea>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('stageBackwardModal').classList.add('hidden')" class="px-3 py-2 border rounded-xl">Hủy</button>
                    <button type="submit" class="px-4 py-2 bg-rose-600 text-white font-bold rounded-xl">Lùi giai đoạn</button>
                </div>
            </form>
        </div>
    </div>
    @endif

    @if ($canBookTrial)
    <!-- Học thử: hoạt động trong giai đoạn tư vấn (không đổi stage) -->
    <div id="scheduleTrialModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl">
            <h3 class="font-bold text-sm mb-1">Đặt lịch học thử</h3>
            <p class="text-xs text-gray-500 mb-2">Chọn 1–2 buổi học thật của lớp cùng trình độ tại {{ $customer->branch?->name ?? 'chi nhánh của khách' }}. Giáo viên của buổi sẽ thấy khách trong trang "Nhận xét học thử" và nhận xét như học sinh chính thức.</p>
            <p class="text-xs mb-4 {{ $trialRemaining > 0 ? 'text-fuchsia-700' : 'text-rose-700' }} font-semibold">Còn đặt được {{ $trialRemaining }}/{{ \App\Models\CrmTrialBooking::MAX_ACTIVE_PER_LEAD }} buổi học thử.@if ($latestSubmission?->finalClass()) Trình độ theo test: {{ $latestSubmission->finalClass() }}.@endif</p>
            <form action="{{ route('crm.customers.trial-bookings.store', $customer->id) }}" method="POST" class="space-y-3 text-xs">
                @csrf
                <div class="max-h-64 overflow-y-auto border border-gray-100 rounded-xl divide-y divide-gray-100">
                    @forelse ($trialSessions as $session)
                        <label class="flex items-start gap-2 p-2.5 hover:bg-fuchsia-50/40 cursor-pointer">
                            <input type="checkbox" name="class_session_ids[]" value="{{ $session->id }}" class="mt-0.5 rounded border-gray-300 text-fuchsia-600" />
                            <span>
                                <span class="font-bold text-gray-900">{{ $session->classModel?->name }}</span>
                                @if ($session->matches_level)<span class="ml-1 px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 font-bold text-[10px]">Khớp trình độ</span>@endif
                                <span class="text-gray-500">· {{ $session->classModel?->course?->name ?? 'Chưa gán khóa' }}{{ $session->classModel?->level ? ' · '.$session->classModel->level : '' }}</span>
                                <span class="block text-gray-500">{{ $session->date->format('d/m/Y') }} · {{ $session->start_time?->format('H:i') }}–{{ $session->end_time?->format('H:i') }} · GV: {{ $session->teacher?->name ?? 'Chưa gán' }}</span>
                            </span>
                        </label>
                    @empty
                        <div class="p-4 text-center text-gray-400">Chưa có buổi học sắp tới phù hợp.</div>
                    @endforelse
                </div>
                <textarea name="notes" rows="2" placeholder="Ghi chú cho giáo viên (trình độ, mục tiêu...)" class="w-full rounded-xl border-gray-200 text-xs"></textarea>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('scheduleTrialModal').classList.add('hidden')" class="px-3 py-2 border rounded-xl">Hủy</button>
                    <button type="submit" class="px-4 py-2 bg-fuchsia-600 text-white font-bold rounded-xl">Lưu lịch học thử</button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <!-- Schedule Test Modal -->
    <div id="scheduleTestModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
            <div class="flex justify-between items-center pb-2 border-b border-gray-100">
                <h3 class="font-bold text-sm text-gray-900 flex items-center gap-2">
                    <span class="material-symbols-outlined text-indigo-600">event</span>
                    Đặt Lịch Hẹn Test Đầu Vào Cho Lead
                </h3>
                <button type="button" onclick="document.getElementById('scheduleTestModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form action="{{ route('crm.customers.schedule-test', $customer->id) }}" method="POST" class="space-y-3 text-xs">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Ngày hẹn test <span class="text-rose-500">*</span></label>
                        <input type="date" name="appointment_date" value="{{ date('Y-m-d') }}" required class="w-full text-xs rounded-xl border border-gray-200 p-2" />
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-700 mb-1">Giờ hẹn <span class="text-rose-500">*</span></label>
                        <input type="time" name="appointment_time" value="14:00" required class="w-full text-xs rounded-xl border border-gray-200 p-2" />
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-gray-700 mb-1">Hình thức làm bài <span class="text-rose-500">*</span></label>
                    <select name="appointment_type" required class="w-full text-xs rounded-xl border border-gray-200 p-2 font-bold text-primary">
                        <option value="online">Trực tuyến (Online qua link Portal)</option>
                        <option value="offline">Tại cơ sở (Offline tại trung tâm)</option>
                    </select>
                </div>

                <div>
                    <label class="block font-semibold text-gray-700 mb-1">Bộ đề kiểm tra gán cho Lead</label>
                    <select name="assigned_test_id" class="w-full text-xs rounded-xl border border-gray-200 p-2">
                        @foreach ($placementTests ?? [] as $test)
                            <option value="{{ $test->id }}">{{ $test->title }} ({{ $test->code }})</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block font-semibold text-gray-700 mb-1">Giáo viên / Giám thị phụ trách chấm</label>
                    <select name="examiner_id" class="w-full text-xs rounded-xl border border-gray-200 p-2">
                        <option value="">-- Tự động chấm AI / Chưa gán --</option>
                        @foreach ($examiners ?? [] as $examiner)
                            <option value="{{ $examiner->id }}">{{ $examiner->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block font-semibold text-gray-700 mb-1">Ghi chú nhắc hẹn</label>
                    <textarea name="notes" rows="2" placeholder="Nhắc học viên mang theo tai nghe, CMND..." class="w-full text-xs rounded-xl border border-gray-200 p-2"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                    <button type="button" onclick="document.getElementById('scheduleTestModal').classList.add('hidden')" class="px-3 py-1.5 rounded-lg border text-xs text-gray-600">Hủy</button>
                    <button type="submit" class="px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-lg shadow-sm">Xác nhận lịch hẹn</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Enter / Edit Test Score Modal -->
    <div id="editTestScoreModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-2xl w-full p-6 space-y-4 shadow-2xl border border-gray-200 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center pb-2 border-b border-gray-100">
                <h3 class="font-bold text-sm text-gray-900 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary-container">military_tech</span>
                    <span>Ghi Nhận &amp; Nhập Điểm Test Đầu Vào</span>
                </h3>
                <button type="button" onclick="document.getElementById('editTestScoreModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form action="{{ route('crm.customers.save-test-score', $customer->id) }}" method="POST" class="space-y-3.5 text-xs">
                @csrf
                @php
                    // Chỉ điền sẵn khi sửa đúng lần thi đang chọn; nhập lần mới thì để trống, không bịa điểm mặc định.
                    $editSub = ($sub && $customer->stage !== 'test_scheduled') ? $sub : null;
                    $scoreTestId = old('placement_test_id', $editSub?->placement_test_id ?? $customer->assigned_test_id);
                @endphp
                @if ($editSub)
                    <input type="hidden" name="submission_id" value="{{ $editSub->id }}">
                @endif
                <div class="p-3 bg-orange-50/60 border border-orange-100 rounded-xl text-[11px] text-orange-950 flex items-start gap-2">
                    <span class="material-symbols-outlined text-primary-container text-base mt-0.5">info</span>
                    <div>
                        <strong>Học viên:</strong> {{ $customer->name }} ({{ $customer->phone }})<br>
                        <span>Chấm theo <strong>thang điểm khối lớp</strong>: Tổng = Nghe + Đọc &amp; Viết + Nói → lớp đề xuất. Lưu điểm sẽ tự chuyển khách sang "Đã test".</span>
                    </div>
                </div>

                @unless ($editSub)
                    <div>
                        <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Đề kiểm tra đã dùng <span class="text-rose-500">*</span></label>
                        <select name="placement_test_id" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 bg-white font-semibold text-gray-900 shadow-2xs">
                            <option value="">— Chọn đề —</option>
                            @foreach ($placementTests as $t)
                                <option value="{{ $t->id }}" @selected((string) $scoreTestId === (string) $t->id)>[{{ $t->code }}] {{ $t->title }}</option>
                            @endforeach
                        </select>
                    </div>
                @endunless

                @include('placement-tests.partials.rubric-score-fields', [
                    'submission' => $editSub,
                    'defaultGroup' => \App\Services\PlacementRubricService::detectGradeGroup($editSub?->test?->code ?? $customer->assignedTest?->code),
                ])

                <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                    <button type="button" onclick="document.getElementById('editTestScoreModal').classList.add('hidden')" class="px-3 py-1.5 rounded-lg border text-xs text-gray-600 hover:bg-gray-50">Hủy</button>
                    <button type="submit" class="px-4 py-2 bg-primary-container hover:bg-primary text-white text-xs font-bold rounded-xl shadow-xs transition cursor-pointer">Lưu Điểm Test</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Success flash banner -->

    @if ($customer->stage === \App\Models\CrmCustomer::STAGE_LOST)
        <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800 flex items-start gap-2" data-testid="lost-banner">
            <span class="material-symbols-outlined text-[18px]">block</span>
            <div>
                <div class="font-bold">Khách Thất bại{{ $customer->lost_at ? ' từ '.$customer->lost_at->format('d/m/Y') : '' }} — không mở lại, giữ để đối soát.</div>
                @if ($customer->lost_reason)<div>Lý do: {{ $customer->lost_reason }}</div>@endif
            </div>
        </div>
    @endif

    @if ($customer->trialBookings->isNotEmpty() || $customer->stage === 'waiting_class')
        <div class="mb-4 grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
            @if ($customer->trialBookings->isNotEmpty())
                <div class="rounded-xl border border-fuchsia-200 bg-fuchsia-50 p-3 space-y-2">
                    <div class="font-bold text-fuchsia-800 flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">school</span>Học thử</div>
                    @foreach ($customer->trialBookings as $booking)
                        <div class="rounded-lg bg-white/70 border border-fuchsia-100 p-2">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-semibold text-gray-900">{{ $booking->classModel?->name }} · {{ $booking->session?->date?->format('d/m/Y') }} {{ $booking->session?->start_time?->format('H:i') }}</span>
                                <span class="px-2 py-0.5 rounded-full font-bold {{ $booking->status === 'attended' ? 'bg-emerald-50 text-emerald-700' : ($booking->status === 'scheduled' ? 'bg-sky-50 text-sky-700' : 'bg-rose-50 text-rose-700') }}">{{ $booking->status_label }}</span>
                            </div>
                            @if ($booking->feedback || $booking->remarks)
                                <div class="mt-1 text-gray-700"><span class="font-semibold">Nhận xét của GV:</span> {{ $booking->rating ? $booking->rating.'/5 · ' : '' }}{{ $booking->remarksSummary() !== '' ? $booking->remarksSummary().' · ' : '' }}{{ $booking->feedback }}</div>
                                <div class="text-[11px] text-gray-400">{{ $booking->feedbackBy?->name }} · {{ $booking->feedback_at?->format('d/m/Y H:i') }}</div>
                            @endif
                            @if ($booking->status === 'scheduled' && $canBookTrial)
                                <form action="{{ route('crm.customers.trial-bookings.cancel', [$customer->id, $booking->id]) }}" method="POST" class="mt-1 flex gap-1">
                                    @csrf
                                    <input name="reason" required placeholder="Lý do hủy" class="flex-1 rounded-lg border-fuchsia-200 text-[11px] py-1" />
                                    <button class="px-2 py-1 rounded-lg bg-fuchsia-700 text-white font-bold text-[11px]">Hủy buổi</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
            @if ($customer->stage === 'waiting_class')
                <div class="rounded-xl border border-yellow-200 bg-yellow-50 p-3">
                    <div class="font-bold text-yellow-800">Đã chốt, chờ xếp lớp{{ $customer->waiting_since ? ' từ '.$customer->waiting_since->format('d/m/Y') : '' }}</div>
                    <div class="text-yellow-700">Khóa: {{ $customer->waitingCourse?->name ?? 'Chưa chọn khóa' }} · {{ $customer->waitingBranch?->name ?? $customer->branch?->name }}</div>
                    <div class="text-yellow-700">Học phí đăng ký: {{ $customer->fee_paid_at_closing ? 'Đã đóng' : 'Chưa đóng (đã tạo task nhắc thu)' }}</div>
                </div>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Customer Info Card -->
        <div class="space-y-6">
            <!-- Trạng thái & Hạn xử lý -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 space-y-3 text-xs">
                <h3 class="font-bold text-gray-900 uppercase tracking-wider flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-primary text-base">pending_actions</span>
                    Trạng thái &amp; Hạn xử lý
                </h3>
                <div class="flex justify-between py-1 border-b border-gray-50">
                    <span class="text-gray-500">Giai đoạn:</span>
                    <span class="px-2 py-0.5 rounded-full border font-bold {{ $customer->stage_badge }}">{{ $customer->stage_label }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-gray-50">
                    <span class="text-gray-500">Ở giai đoạn này:</span>
                    <span class="font-semibold text-gray-900">{{ $statusCard['days_in_stage'] }} ngày <span class="text-gray-400 font-normal">(từ {{ $statusCard['stage_since']->format('d/m/Y') }})</span></span>
                </div>
                <div class="flex justify-between py-1 border-b border-gray-50">
                    <span class="text-gray-500">Liên hệ gần nhất:</span>
                    <span class="font-semibold text-gray-900">{{ $statusCard['last_contact']?->format('d/m/Y H:i') ?? 'Chưa có' }}</span>
                </div>
                <div class="flex justify-between items-center py-1 border-b border-gray-50">
                    <span class="text-gray-500">Hạn liên hệ tiếp theo:</span>
                    <span class="flex items-center gap-1.5">
                        <span class="font-semibold text-gray-900">{{ $customer->next_follow_up_at?->format('d/m/Y H:i') ?? 'Chưa đặt' }}</span>
                        @if ($statusCard['follow_up_status'] === 'overdue')
                            <x-ui.badge color="status-overdue">Quá hạn</x-ui.badge>
                        @elseif ($statusCard['follow_up_status'] === 'due_soon')
                            <x-ui.badge color="warning">Sắp hết hạn</x-ui.badge>
                        @endif
                    </span>
                </div>
                @if ($statusCard['neglected'])
                    <div class="p-2.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 font-semibold flex items-start gap-1.5">
                        <span class="material-symbols-outlined text-base">person_alert</span>
                        <span>Khách chưa có hoạt động chăm sóc nào trong {{ $statusCard['neglect_days'] }} ngày gần đây.</span>
                    </div>
                @endif
                @can('lead.update')
                    <a href="{{ route('crm.customers.edit', $customer->id) }}#next_follow_up_at" class="text-primary font-semibold hover:underline inline-flex items-center gap-0.5">
                        <span class="material-symbols-outlined text-[14px]">event</span>Đặt hạn liên hệ
                    </a>
                @endcan
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 space-y-4">
                <div class="flex items-center gap-3 pb-4 border-b border-gray-100">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-primary-container/20 to-orange-100 text-primary flex items-center justify-center font-bold text-lg shadow-sm">
                        {{ Str::substr($customer->name, 0, 1) }}
                    </div>
                    <div>
                        <h2 class="font-bold text-gray-900 text-sm">{{ $customer->name }}</h2>
                        <span class="text-xs text-gray-400 font-mono">{{ $customer->email ?? 'Chưa có email' }}</span>
                    </div>
                </div>

                <div class="space-y-2.5 text-xs">
                    <div class="flex justify-between py-1 border-b border-gray-50">
                        <span class="text-gray-500">Mã khách hàng:</span>
                        <span class="font-bold text-gray-900 font-mono">{{ $customer->code }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-gray-50">
                        <span class="text-gray-500">Số điện thoại:</span>
                        <span class="font-semibold text-gray-900 font-mono">{{ $customer->phone }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-gray-50">
                        <span class="text-gray-500">Phụ huynh:</span>
                        <span class="font-medium text-gray-900">{{ $customer->parent_name ?: '—' }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-gray-50">
                        <span class="text-gray-500">SĐT phụ huynh:</span>
                        <span class="font-semibold text-gray-900 font-mono">{{ $customer->parent_phone ?: '—' }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-gray-50">
                        <span class="text-gray-500">Ngày sinh / Giới tính:</span>
                        <span class="font-medium text-gray-800">{{ $customer->dob ? $customer->dob->format('d/m/Y') : '—' }} ({{ $customer->gender ?? 'Chưa rõ' }})</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-gray-50">
                        <span class="text-gray-500">Khóa quan tâm:</span>
                        <span class="font-bold text-primary">{{ $customer->course_interest ?? 'Chưa chọn' }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-gray-50">
                        <span class="text-gray-500">Giá trị Deal:</span>
                        <span class="font-mono font-bold text-gray-900">{{ number_format($customer->deal_value) }}đ</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-gray-50">
                        <span class="text-gray-500">Nguồn Lead:</span>
                        <span class="font-medium text-gray-800">{{ $customer->source ?? 'Trực tiếp' }}</span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-gray-50">
                        <span class="text-gray-500">Sales phụ trách:</span>
                        <span class="font-semibold text-indigo-700">{{ $customer->assignedUser?->name ?? 'Chưa phân công' }}</span>
                    </div>
                    <div class="pt-2">
                        <span class="text-gray-500 block mb-1">Địa chỉ:</span>
                        <span class="font-medium text-gray-800">{{ $customer->address ?? 'Chưa cập nhật' }}</span>
                    </div>
                    @if ($customer->notes)
                        <div class="pt-2 p-2.5 bg-gray-50 rounded-xl border border-gray-100">
                            <span class="text-[11px] font-bold text-gray-600 block mb-1">Ghi chú nhu cầu:</span>
                            <p class="text-gray-700 leading-relaxed">{{ $customer->notes }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- ────────────────────────────────────────────── -->
            <!-- KHỐI TEST ONLINE & THANG ĐIỂM TỰ ĐỘNG (MOCKUP MATCH) -->
            <!-- ────────────────────────────────────────────── -->
            @php
                $submission = $latestSubmission ?? $customer->latestSubmission ?? $customer->submissions->first();
                $hasResult = !empty($submission) || !empty($customer->test_score);
                $hasScheduled = !empty($customer->appointment_at) || !empty($customer->assigned_test_id);
            @endphp

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden" x-data="crmOnlineTestEngine({
                hasResult: {{ $hasResult ? 'true' : 'false' }},
                hasScheduled: {{ $hasScheduled ? 'true' : 'false' }},
                testLink: {{ Js::from($portalTestLink) }}
            })">
                <!-- Header with State Badges -->
                <div class="bg-slate-50/80 px-4 py-3 border-b border-gray-200 flex justify-between items-center flex-wrap gap-2">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-lg">quiz</span>
                        <h3 class="font-bold text-gray-900 text-xs uppercase tracking-wider">Đề Test Online &amp; Kết Quả Điểm Số</h3>
                    </div>
                    <div>
                        @if ($hasResult)
                            <div class="flex items-center gap-1.5">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 uppercase">
                                    Đã có kết quả
                                </span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-orange-50 text-orange-700 border border-orange-200 flex items-center gap-0.5">
                                    <span class="material-symbols-outlined text-[12px]">military_tech</span>
                                    <span>{{ $submission?->scoreSummary() ?? $customer->test_score }}</span>
                                </span>
                            </div>
                        @elseif ($hasScheduled)
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200 uppercase">
                                Đã gửi link
                            </span>
                        @else
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200 uppercase">
                                Chưa gửi đề
                            </span>
                        @endif
                    </div>
                </div>

                <!-- STATE 1: CHƯA GỬI ĐỀ -->
                @if (!$hasResult && !$hasScheduled)
                    <div class="p-5 space-y-4">
                        <form action="{{ route('crm.customers.schedule-test', $customer->id) }}" method="POST" class="space-y-3">
                            @csrf
                            <input type="hidden" name="appointment_type" value="online" />

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                <div>
                                    <label class="font-bold text-gray-700 uppercase tracking-wider text-[10px] block mb-1">Ngày hẹn làm test</label>
                                    <input type="date" name="appointment_date" required min="{{ now()->toDateString() }}" value="{{ old('appointment_date', now()->addDay()->toDateString()) }}" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 bg-white font-semibold text-gray-900">
                                </div>
                                <div>
                                    <label class="font-bold text-gray-700 uppercase tracking-wider text-[10px] block mb-1">Giờ hẹn</label>
                                    <input type="time" name="appointment_time" required value="{{ old('appointment_time', '09:00') }}" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 bg-white font-semibold text-gray-900">
                                </div>
                                <div>
                                    <label class="font-bold text-gray-700 uppercase tracking-wider text-[10px] block mb-1">Danh sách đề tương ứng</label>
                                    <select name="assigned_test_id" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold text-gray-900 bg-white">
                                        @foreach ($placementTests as $t)
                                            <option value="{{ $t->id }}">[{{ $t->code }}] {{ $t->title }} ({{ $t->duration_minutes }}')</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="flex items-center justify-between gap-2 pt-2 flex-wrap">
                                @can('entrance_test.grade')
                                <button type="button" onclick="document.getElementById('editTestScoreModal').classList.remove('hidden')" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-semibold transition flex items-center gap-1 cursor-pointer">
                                    <span class="material-symbols-outlined text-[15px]">edit_note</span>
                                    <span>Nhập điểm trực tiếp</span>
                                </button>
                                @endcan
                                <button type="submit" class="w-full sm:w-auto px-4 py-2.5 bg-primary-container hover:bg-primary text-white rounded-xl text-xs font-bold shadow-sm transition flex items-center justify-center gap-1.5 cursor-pointer">
                                    <span class="material-symbols-outlined text-[16px]">send</span>
                                    <span>Gửi link test online</span>
                                </button>
                            </div>
                        </form>
                    </div>

                <!-- STATE 2: ĐÃ GỬI LINK (Chờ học viên làm bài) -->
                @elseif (!$hasResult && $hasScheduled)
                    <div class="p-5 space-y-4">
                        <div class="p-3.5 bg-blue-50/70 border border-blue-200 rounded-xl flex items-start justify-between gap-3 text-xs">
                            <div class="flex items-start gap-3">
                                <span class="material-symbols-outlined text-blue-600 text-lg shrink-0 mt-0.5">schedule_send</span>
                                <div class="space-y-0.5">
                                    <p class="font-bold text-blue-950">Đã gửi link - Chờ học viên làm bài</p>
                                    <p class="text-[11px] text-blue-700 italic">
                                        Link kích hoạt lúc {{ $customer->appointment_at ? $customer->appointment_at->format('H:i, d/m/Y') : date('H:i, d/m/Y') }}
                                    </p>
                                </div>
                            </div>
                            @can('entrance_test.grade')
                            <button type="button" onclick="document.getElementById('editTestScoreModal').classList.remove('hidden')" class="px-3 py-1.5 bg-white border border-blue-200 hover:bg-blue-50 text-blue-700 font-bold rounded-lg text-[11px] shrink-0 transition flex items-center gap-1">
                                <span class="material-symbols-outlined text-[14px]">edit</span>
                                <span>Nhập điểm ngay</span>
                            </button>
                            @endcan
                        </div>

                        <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl flex items-center justify-between gap-2 text-xs">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-lg bg-orange-100 text-orange-600 flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined text-base">description</span>
                                </div>
                                <div>
                                    <h4 class="font-bold text-gray-900">{{ $customer->assignedTest?->title ?? 'Bài Test Đầu Vào MEnglish' }}</h4>
                                    <p class="text-[11px] text-gray-500 font-mono">Mã: {{ $customer->assignedTest?->code ?? '—' }} · {{ $customer->assignedTest?->duration_minutes ?? '—' }} phút</p>
                                </div>
                            </div>
                        </div>

                        @if ($portalTestLink)
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1">
                            <a href="{{ $portalTestLink }}" target="_blank" class="w-full py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-xs transition flex items-center justify-center gap-1.5">
                                <span class="material-symbols-outlined text-[15px]">open_in_new</span>
                                <span>Mở Cổng Test Ngay</span>
                            </a>
                            <button type="button" @click="navigator.clipboard.writeText(testLink); alert('Đã sao chép đường dẫn bài test vào clipboard!');" class="w-full py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 font-semibold text-xs rounded-xl shadow-2xs transition flex items-center justify-center gap-1.5 cursor-pointer">
                                <span class="material-symbols-outlined text-[15px]">content_copy</span>
                                <span>Sao chép Link Test</span>
                            </button>
                        </div>
                        <p class="text-[10px] text-gray-400 italic">Link riêng của lead, có hiệu lực {{ \App\Services\PlacementPortalLinkService::LINK_TTL_DAYS }} ngày kể từ lúc mở trang này.</p>
                        @else
                        <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl text-[11px] text-amber-900">
                            Lead chưa được gán đề test đang hoạt động nên chưa thể tạo link làm bài.
                        </div>
                        @endif
                    </div>

                <!-- STATE 3: ĐÃ CÓ KẾT QUẢ & THANG ĐIỂM TỰ ĐỘNG -->
                @else
                    <div class="p-5 space-y-4">
                        @include('placement-tests.partials.rubric-result', ['submission' => $submission, 'rubric' => $rubric, 'fallbackScore' => $customer->test_score])

                        <!-- Action Buttons -->
                        <div class="flex flex-wrap items-center justify-between gap-2 pt-1 border-t border-gray-100">
                            <div class="flex items-center gap-2">
                                @if ($submission)
                                    <a href="{{ \Illuminate\Support\Facades\URL::signedRoute('portal.test.scorecard', ['id' => $submission->id]) }}" target="_blank" class="px-3 py-1.5 bg-slate-900 hover:bg-slate-800 text-white rounded-lg text-xs font-bold transition flex items-center gap-1 shadow-2xs">
                                        <span class="material-symbols-outlined text-[14px] text-amber-400">description</span>
                                        <span>Bảng Điểm Scorecard</span>
                                    </a>
                                    <a href="{{ route('placement-tests.results.show', $submission->id) }}" class="px-3 py-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-lg text-xs font-semibold transition flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[14px] text-indigo-600">assignment_turned_in</span>
                                        <span>Chi tiết bài làm</span>
                                    </a>
                                @endif
                                @can('entrance_test.grade')
                                <button type="button" onclick="document.getElementById('editTestScoreModal').classList.remove('hidden')" class="px-3 py-1.5 bg-orange-50 border border-orange-200 hover:bg-orange-100 text-primary-container rounded-lg text-xs font-bold transition flex items-center gap-1 cursor-pointer">
                                    <span class="material-symbols-outlined text-[14px]">edit_note</span>
                                    <span>Sửa điểm</span>
                                </button>
                                @endcan
                            </div>
                            <a href="{{ route('placement-tests.rubric-guide') }}" class="text-[11px] font-semibold text-orange-600 hover:underline flex items-center gap-0.5">
                                <span>Thang Điểm Rubric</span>
                                <span class="material-symbols-outlined text-[13px]">arrow_forward</span>
                            </a>
                        </div>
                    </div>
                @endif
            </div>

            <script>
                function crmOnlineTestEngine(cfg) {
                    return { hasResult: cfg.hasResult, hasScheduled: cfg.hasScheduled, testLink: cfg.testLink };
                }
            </script>
        </div>

        <!-- Activity Timeline & Care History -->
        <div class="lg:col-span-2 space-y-6">
            @if ($customer->converted_student_id)
                <!-- Chăm sóc tháng đầu (khách đã chốt) -->
                @php($careState = $customer->care_checklist ?? [])
                <form action="{{ route('crm.customers.care-checklist', $customer->id) }}" method="POST" class="bg-white rounded-2xl border border-emerald-200 shadow-sm p-4 space-y-3 text-xs">
                    @csrf
                    <div class="flex items-center justify-between gap-2 pb-1 border-b border-gray-100">
                        <h3 class="font-bold text-gray-900 uppercase tracking-wider flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-emerald-600 text-base">volunteer_activism</span>
                            Chăm sóc tháng đầu
                        </h3>
                        <span class="text-gray-500">{{ collect($careState)->filter()->count() }}/{{ count(\App\Models\CrmCustomer::CARE_CHECKLIST_ITEMS) }} việc</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                        @foreach (\App\Models\CrmCustomer::CARE_CHECKLIST_ITEMS as $key => $label)
                            <label class="flex items-start gap-2 p-2 rounded-xl border {{ ! empty($careState[$key]) ? 'border-emerald-200 bg-emerald-50/50' : 'border-gray-100' }}">
                                <input type="checkbox" name="items[]" value="{{ $key }}" @checked(! empty($careState[$key])) @cannot('lead.update') disabled @endcannot class="mt-0.5 rounded border-gray-300 text-emerald-600">
                                <span>
                                    <span class="font-semibold text-gray-900">{{ $label }}</span>
                                    @if (! empty($careState[$key]['done_at']))
                                        <span class="block text-[10px] text-gray-500">{{ \Illuminate\Support\Carbon::parse($careState[$key]['done_at'])->format('d/m/Y H:i') }} · {{ $careState[$key]['by'] ?? '' }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @can('lead.update')
                        <div class="flex items-center gap-2">
                            <input type="text" name="note" maxlength="1000" placeholder="Ghi chú chăm sóc (tuỳ chọn)" class="flex-1 rounded-xl border-gray-200 text-xs">
                            <x-ui.button type="submit" size="sm" icon="save">Lưu checklist</x-ui.button>
                        </div>
                    @endcan
                </form>
            @endif

            <!-- Quick Log Note Box -->
            <form action="{{ route('crm.customers.notes.store', $customer->id) }}" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 space-y-3" x-data="{ noteType: 'call' }">
                @csrf
                <input type="hidden" name="type" :value="noteType" />

                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-1 border-b border-gray-100">
                    <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wider flex items-center gap-1.5 shrink-0">
                        <span class="material-symbols-outlined text-primary text-base">edit_note</span>
                        <span>Ghi nhật ký chăm sóc</span>
                    </h3>
                    <div class="flex flex-wrap items-center gap-1.5 text-xs">
                        <button type="button" @click="noteType = 'call'" :class="noteType === 'call' ? 'bg-primary-container text-white shadow-sm ring-1 ring-primary-container' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-3 py-1.5 rounded-xl font-semibold transition whitespace-nowrap inline-flex items-center gap-1.5 shrink-0">
                            <span>📞</span> <span>Gọi điện</span>
                        </button>
                        <button type="button" @click="noteType = 'message'" :class="noteType === 'message' ? 'bg-primary-container text-white shadow-sm ring-1 ring-primary-container' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-3 py-1.5 rounded-xl font-semibold transition whitespace-nowrap inline-flex items-center gap-1.5 shrink-0">
                            <span>💬</span> <span>Nhắn tin</span>
                        </button>
                        <button type="button" @click="noteType = 'meet'" :class="noteType === 'meet' ? 'bg-primary-container text-white shadow-sm ring-1 ring-primary-container' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-3 py-1.5 rounded-xl font-semibold transition whitespace-nowrap inline-flex items-center gap-1.5 shrink-0">
                            <span>🤝</span> <span>Gặp trực tiếp</span>
                        </button>
                        <button type="button" @click="noteType = 'test'" :class="noteType === 'test' ? 'bg-primary-container text-white shadow-sm ring-1 ring-primary-container' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-3 py-1.5 rounded-xl font-semibold transition whitespace-nowrap inline-flex items-center gap-1.5 shrink-0">
                            <span>📝</span> <span>Test thử</span>
                        </button>
                        <button type="button" @click="noteType = 'note'" :class="noteType === 'note' ? 'bg-primary-container text-white shadow-sm ring-1 ring-primary-container' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="px-3 py-1.5 rounded-xl font-semibold transition whitespace-nowrap inline-flex items-center gap-1.5 shrink-0">
                            <span>📌</span> <span>Ghi chú</span>
                        </button>
                    </div>
                </div>

                <textarea name="content" rows="3" required placeholder="Nhập nội dung trao đổi, phản hồi của khách hàng..." class="w-full text-xs rounded-xl border border-gray-200 p-3"></textarea>

                <div class="flex justify-end">
                    <button type="submit" class="px-4 py-2 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px]">send</span>
                        <span>Lưu nhật ký</span>
                    </button>
                </div>
            </form>

            <!-- Timeline -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-4">
                <div class="flex flex-col gap-2 pb-2 border-b border-gray-100">
                    <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wider">
                        Lịch sử tương tác &amp; Tiến trình chăm sóc ({{ $histories->count() }}{{ $logType ? '/'.$customer->histories->count() : '' }})
                    </h3>
                    <nav class="flex flex-wrap gap-1.5 text-[11px]" aria-label="Lọc nhật ký">
                        <a href="{{ route('crm.customers.show', $customer->id) }}#timeline" class="px-2.5 py-1 rounded-full border {{ ! $logType ? 'bg-primary-container text-white border-primary-container' : 'border-gray-200 text-gray-600 hover:bg-gray-50' }}">Tất cả</a>
                        @foreach (\App\Models\CrmCustomerHistory::FILTER_TYPES as $typeKey => $typeLabel)
                            @php($typeCount = $customer->histories->where('type', $typeKey)->count())
                            @if ($typeCount > 0)
                                <a href="{{ route('crm.customers.show', ['id' => $customer->id, 'log_type' => $typeKey]) }}#timeline" class="px-2.5 py-1 rounded-full border {{ $logType === $typeKey ? 'bg-primary-container text-white border-primary-container' : 'border-gray-200 text-gray-600 hover:bg-gray-50' }}">{{ $typeLabel }} ({{ $typeCount }})</a>
                            @endif
                        @endforeach
                    </nav>
                </div>

                <div class="space-y-4" id="timeline">
                    @forelse ($histories as $history)
                        <div class="flex items-start gap-3 text-xs">
                            <div class="w-8 h-8 rounded-full bg-gray-100 text-gray-700 flex items-center justify-center shrink-0 font-bold">
                                @if ($history->type === 'call')
                                    <span class="material-symbols-outlined text-base text-blue-600">call</span>
                                @elseif ($history->type === 'message')
                                    <span class="material-symbols-outlined text-base text-emerald-600">chat</span>
                                @elseif ($history->type === 'test')
                                    <span class="material-symbols-outlined text-base text-indigo-600">quiz</span>
                                @elseif ($history->type === 'stage_change')
                                    <span class="material-symbols-outlined text-base text-amber-600">sync_alt</span>
                                @elseif (in_array($history->type, ['update', 'assign', 'care', 'trial', 'meet'], true))
                                    <span class="material-symbols-outlined text-base text-slate-600">{{ $history->type_icon }}</span>
                                @else
                                    <span class="material-symbols-outlined text-base text-gray-600">notes</span>
                                @endif
                            </div>
                            <div class="flex-1 p-3.5 bg-gray-50 rounded-2xl border border-gray-100 space-y-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-gray-900">{{ $history->user?->name ?? 'Hệ thống' }}</span>
                                    <span class="text-[10px] text-gray-400 font-mono">{{ $history->created_at->format('d/m/Y H:i') }}</span>
                                </div>
                                <p class="text-gray-700 leading-relaxed whitespace-pre-line">{{ $history->content }}</p>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-6 text-gray-400 text-xs">Chưa có lịch sử chăm sóc nào.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        // Confirm xoá lead qua data-confirm (thay cho inline onsubmit — tránh XSS qua tên lead)
        document.addEventListener('submit', function (event) {
            const form = event.target instanceof Element ? event.target.closest('form[data-confirm]') : null;
            if (form && !window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        }, true);
    </script>
    @endpush
</x-app-layout>
