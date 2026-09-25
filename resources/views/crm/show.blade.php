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
                        <span>Đã Làm Bài Test ({{ $sub?->overall_score ?? $customer->test_score }})</span>
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

                @can('lead.update')
                @if (in_array($customer->stage, ['tested', 'trial_scheduled'], true))
                <button type="button" onclick="document.getElementById('scheduleTrialModal').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-fuchsia-50 text-fuchsia-700 border border-fuchsia-200 text-xs font-bold">
                    <span class="material-symbols-outlined text-[16px]">school</span><span>Hẹn học thử</span>
                </button>
                @endif
                @if (in_array($customer->stage, ['consulting', 'waiting_class'], true))
                <button type="button" onclick="document.getElementById('waitingListModal').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-yellow-50 text-yellow-700 border border-yellow-200 text-xs font-bold">
                    <span class="material-symbols-outlined text-[16px]">hourglass_top</span><span>Chờ lớp</span>
                </button>
                @endif
                <a href="{{ route('crm.customers.edit', $customer->id) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-xs font-semibold text-gray-700 transition whitespace-nowrap shrink-0 shadow-2xs">
                    <span class="material-symbols-outlined text-[16px]">edit</span>
                    <span>Sửa thông tin</span>
                </a>
                @endcan

                @can('entrance_test.grade')
                @if (in_array($customer->stage, ['trial_scheduled', 'trial_completed']))
                    <button type="button" onclick="document.getElementById('trialFeedbackModal').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-teal-50 text-teal-700 border border-teal-200 text-xs font-bold">
                        <span class="material-symbols-outlined text-[16px]">rate_review</span><span>Phản hồi học thử</span>
                    </button>
                @endif
                @endcan

                @can('lead.convert')
                @if (in_array($customer->stage, ['trial_completed', 'waiting_class', 'closing'], true))
                <a href="{{ route('crm.closing-wizard', ['customer_id' => $customer->id]) }}" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold shadow-sm transition whitespace-nowrap shrink-0">
                    <span class="material-symbols-outlined text-[16px] text-amber-400">route</span>
                    <span>Chốt &amp; Xếp lớp</span>
                </a>
                @endif
                @endcan

                @can('lead.mark_lost')
                @if (! in_array($customer->stage, ['won', 'lost']))
                    <button type="button" onclick="document.getElementById('markLostModal').classList.remove('hidden')" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-rose-50 text-rose-700 border border-rose-200 text-xs font-bold">
                        <span class="material-symbols-outlined text-[16px]">cancel</span><span>Không chốt</span>
                    </button>
                @endif
                @endcan

                @can('lead.delete')
                @if ($customer->stage !== 'won' && !$customer->converted_student_id)
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

    <div id="markLostModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl">
            <h3 class="font-bold text-sm mb-4">Ghi nhận lý do không chốt</h3>
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

    <!-- Trial lesson workflow -->
    <div id="scheduleTrialModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl">
            <h3 class="font-bold text-sm mb-4">Hẹn buổi học thử</h3>
            <form action="{{ route('crm.customers.schedule-trial', $customer->id) }}" method="POST" class="space-y-3 text-xs">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <input type="date" name="trial_date" value="{{ now()->addDay()->format('Y-m-d') }}" required class="rounded-xl border-gray-200 text-xs" />
                    <input type="time" name="trial_time" value="18:00" required class="rounded-xl border-gray-200 text-xs" />
                </div>
                <select name="trial_teacher_id" required class="w-full rounded-xl border-gray-200 text-xs">
                    <option value="">-- Chọn giáo viên --</option>
                    @foreach ($examiners as $examiner)<option value="{{ $examiner->id }}">{{ $examiner->name }}</option>@endforeach
                </select>
                <div class="grid grid-cols-2 gap-3">
                    <select name="trial_mode" required class="w-full rounded-xl border-gray-200 text-xs">
                        <option value="offline">Tại trung tâm</option>
                        <option value="online">Online</option>
                    </select>
                    <select name="trial_class_id" class="w-full rounded-xl border-gray-200 text-xs">
                        <option value="">-- Chưa gán lớp --</option>
                        @foreach ($trialClasses as $trialClass)
                            <option value="{{ $trialClass->id }}">{{ $trialClass->name }} · {{ $trialClass->schedule_text ?: 'Chưa có lịch' }}</option>
                        @endforeach
                    </select>
                </div>
                <textarea name="notes" rows="2" placeholder="Ghi chú buổi học thử" class="w-full rounded-xl border-gray-200 text-xs"></textarea>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('scheduleTrialModal').classList.add('hidden')" class="px-3 py-2 border rounded-xl">Hủy</button>
                    <button type="submit" class="px-4 py-2 bg-fuchsia-600 text-white font-bold rounded-xl">Lưu lịch</button>
                </div>
            </form>
        </div>
    </div>

    <div id="trialFeedbackModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl">
            <h3 class="font-bold text-sm mb-4">Phản hồi buổi học thử</h3>
            <form action="{{ route('crm.customers.trial-feedback', $customer->id) }}" method="POST" class="space-y-3 text-xs">
                @csrf
                <select name="trial_rating" required class="w-full rounded-xl border-gray-200 text-xs">
                    @foreach ([5, 4, 3, 2, 1] as $rating)<option value="{{ $rating }}" @selected(old('trial_rating', $customer->trial_rating) == $rating)>{{ $rating }}/5</option>@endforeach
                </select>
                <textarea name="trial_feedback" rows="4" required placeholder="Mức độ phù hợp, tương tác, đề xuất lộ trình..." class="w-full rounded-xl border-gray-200 text-xs">{{ old('trial_feedback', $customer->trial_feedback) }}</textarea>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('trialFeedbackModal').classList.add('hidden')" class="px-3 py-2 border rounded-xl">Hủy</button>
                    <button type="submit" class="px-4 py-2 bg-teal-600 text-white font-bold rounded-xl">Lưu phản hồi</button>
                </div>
            </form>
        </div>
    </div>

    @if($customer->stage === 'trial_scheduled')
    <div class="mb-4 rounded-xl border border-fuchsia-200 bg-fuchsia-50 p-3 text-xs flex flex-wrap items-center justify-between gap-3">
        <span class="font-semibold text-fuchsia-800">Buổi học thử đang chờ: có thể nhập phản hồi, hẹn lại hoặc ghi nhận hủy/vắng.</span>
        <form action="{{ route('crm.customers.trial-status', $customer->id) }}" method="POST" class="flex flex-wrap gap-2 items-center">
            @csrf
            <select name="trial_status" class="rounded-lg border-fuchsia-200 text-xs"><option value="cancelled">Đã hủy</option><option value="no_show">Vắng mặt</option></select>
            <input name="reason" required placeholder="Lý do" class="rounded-lg border-fuchsia-200 text-xs" />
            <button class="px-3 py-2 rounded-lg bg-fuchsia-700 text-white font-bold">Ghi nhận</button>
        </form>
    </div>
    @endif

    <div id="waitingListModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl">
            <h3 class="font-bold text-sm mb-4">Đưa Lead vào danh sách chờ lớp</h3>
            <form action="{{ route('crm.customers.waiting-list', $customer->id) }}" method="POST" class="space-y-3 text-xs">
                @csrf
                <input name="preferred_schedule" required value="{{ old('preferred_schedule', $customer->preferred_schedule) }}" placeholder="Ca học mong muốn, ví dụ T2-T4-T6 19:30" class="w-full rounded-xl border-gray-200 text-xs" />
                <div class="grid grid-cols-2 gap-3">
                    <select name="waiting_course_id" required class="w-full rounded-xl border-gray-200 text-xs">
                        <option value="">-- Khóa học --</option>
                        @foreach ($courses as $course)<option value="{{ $course->id }}" @selected(old('waiting_course_id', $customer->waiting_course_id) == $course->id)>{{ $course->name }}</option>@endforeach
                    </select>
                    <select name="waiting_branch_id" required class="w-full rounded-xl border-gray-200 text-xs">
                        <option value="">-- Cơ sở --</option>
                        @foreach ($branches as $branch)<option value="{{ $branch->id }}" @selected(old('waiting_branch_id', $customer->waiting_branch_id ?? $customer->branch_id) == $branch->id)>{{ $branch->name }}</option>@endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <input type="date" name="desired_start_date" min="{{ today()->format('Y-m-d') }}" value="{{ old('desired_start_date', $customer->desired_start_date?->format('Y-m-d')) }}" class="w-full rounded-xl border-gray-200 text-xs" />
                    <select name="waiting_priority" required class="w-full rounded-xl border-gray-200 text-xs">
                        @foreach ([1 => '1 - Thấp', 2 => '2', 3 => '3 - Bình thường', 4 => '4', 5 => '5 - Cao'] as $value => $label)<option value="{{ $value }}" @selected(old('waiting_priority', $customer->waiting_priority ?? 3) == $value)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <textarea name="waiting_notes" rows="2" placeholder="Ghi chú ghép lớp / liên hệ" class="w-full rounded-xl border-gray-200 text-xs">{{ old('waiting_notes', $customer->waiting_notes) }}</textarea>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('waitingListModal').classList.add('hidden')" class="px-3 py-2 border rounded-xl">Hủy</button>
                    <button type="submit" class="px-4 py-2 bg-yellow-600 text-white font-bold rounded-xl">Xác nhận chờ lớp</button>
                </div>
            </form>
        </div>
    </div>

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
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl border border-gray-200">
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
                @if ($sub && $customer->stage !== 'test_scheduled')
                    <input type="hidden" name="submission_id" value="{{ $sub->id }}">
                @endif
                <div class="p-3 bg-orange-50/60 border border-orange-100 rounded-xl text-[11px] text-orange-950 flex items-start gap-2">
                    <span class="material-symbols-outlined text-primary-container text-base mt-0.5">info</span>
                    <div>
                        <strong>Học viên:</strong> {{ $customer->name }} ({{ $customer->phone }})<br>
                        <span>Nhập điểm 4 kỹ năng để hệ thống tự động tính Overall Band &amp; cập nhật giai đoạn CRM.</span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Nghe (Listening) <span class="text-rose-500">*</span></label>
                        <input type="number" step="0.5" min="0" max="100" name="listening_score" value="{{ $sub->listening_score ?? 6.0 }}" required class="w-full text-xs font-mono font-bold text-indigo-700 rounded-xl border border-gray-200 p-2.5 bg-white shadow-2xs" />
                    </div>
                    <div>
                        <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Đọc (Reading) <span class="text-rose-500">*</span></label>
                        <input type="number" step="0.5" min="0" max="100" name="reading_score" value="{{ $sub->reading_score ?? 6.5 }}" required class="w-full text-xs font-mono font-bold text-emerald-700 rounded-xl border border-gray-200 p-2.5 bg-white shadow-2xs" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Viết (Writing)</label>
                        <input type="number" step="0.5" min="0" max="100" name="writing_score" value="{{ $sub->writing_score ?? 5.5 }}" class="w-full text-xs font-mono font-bold text-amber-700 rounded-xl border border-gray-200 p-2.5 bg-white shadow-2xs" />
                    </div>
                    <div>
                        <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Nói (Speaking) <span class="text-rose-500">*</span></label>
                        <input type="number" step="0.5" min="0" max="100" name="speaking_score" value="{{ $sub->speaking_score ?? 6.0 }}" required class="w-full text-xs font-mono font-bold text-rose-700 rounded-xl border border-gray-200 p-2.5 bg-white shadow-2xs" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Trình độ CEFR</label>
                        <select name="cefr_level" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 bg-white font-bold text-gray-900 shadow-2xs">
                            <option value="A1" @selected(($sub->cefr_level ?? '') === 'A1')>A1 - Mất gốc (Beginner)</option>
                            <option value="A2" @selected(($sub->cefr_level ?? '') === 'A2')>A2 - Sơ cấp (Elementary)</option>
                            <option value="B1" @selected(($sub->cefr_level ?? 'B1') === 'B1')>B1 - Trung cấp (Intermediate)</option>
                            <option value="B2" @selected(($sub->cefr_level ?? '') === 'B2')>B2 - Khá (Upper-Inter)</option>
                            <option value="C1" @selected(($sub->cefr_level ?? '') === 'C1')>C1 - Cao cấp (Advanced)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Khóa học đề xuất</label>
                        <input type="text" name="recommended_course" value="{{ $sub->recommended_course ?? ($customer->course_interest ?? 'IELTS 6.5 Intensive') }}" class="w-full text-xs font-bold text-gray-900 rounded-xl border border-gray-200 p-2.5 bg-white shadow-2xs" />
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Nhận xét &amp; Lời phê của Giáo viên</label>
                    <textarea name="teacher_comments" rows="2" class="w-full text-xs rounded-xl border border-gray-200 p-2.5 bg-white shadow-2xs leading-relaxed" placeholder="VD: Học viên phát âm tự nhiên, phản xạ nói tốt, cần luyện thêm ngữ pháp...">{{ $sub->teacher_comments ?? 'Học viên có phản xạ nói tự nhiên, vốn từ cơ bản tốt. Cần rèn thêm kỹ năng viết và ngữ pháp nâng cao.' }}</textarea>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                    <button type="button" onclick="document.getElementById('editTestScoreModal').classList.add('hidden')" class="px-3 py-1.5 rounded-lg border text-xs text-gray-600 hover:bg-gray-50">Hủy</button>
                    <button type="submit" class="px-4 py-2 bg-primary-container hover:bg-primary text-white text-xs font-bold rounded-xl shadow-xs transition cursor-pointer">Lưu Điểm Test</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Success flash banner -->
    @if (session('status'))
        <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-2xl text-xs font-semibold text-emerald-800 flex items-center gap-2 shadow-sm">
            <span class="material-symbols-outlined text-emerald-600 text-base">check_circle</span>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    @if ($customer->trial_at || $customer->waiting_since)
        <div class="mb-4 grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
            @if ($customer->trial_at)
                <div class="rounded-xl border border-fuchsia-200 bg-fuchsia-50 p-3">
                    <div class="font-bold text-fuchsia-800">Học thử: {{ $customer->trial_at->format('d/m/Y H:i') }}</div>
                    <div class="text-fuchsia-700">GV: {{ $customer->trialTeacher?->name ?? 'Chưa gán' }} · {{ $customer->trial_mode === 'online' ? 'Online' : 'Tại trung tâm' }} · Lớp: {{ $customer->trialClass?->name ?? 'Chưa gán' }} · Đánh giá: {{ $customer->trial_rating ? $customer->trial_rating.'/5' : 'Chưa có' }}</div>
                    @if ($customer->trial_feedback)<div class="mt-1 text-gray-700">{{ $customer->trial_feedback }}</div>@endif
                </div>
            @endif
            @if ($customer->waiting_since)
                <div class="rounded-xl border border-yellow-200 bg-yellow-50 p-3">
                    <div class="font-bold text-yellow-800">Chờ lớp từ {{ $customer->waiting_since->format('d/m/Y') }}</div>
                    <div class="text-yellow-700">{{ $customer->waitingCourse?->name ?? 'Chưa chọn khóa' }} · {{ $customer->waitingBranch?->name ?? 'Chưa chọn cơ sở' }} · Ưu tiên {{ $customer->waiting_priority ?? 3 }}/5</div>
                    <div class="text-yellow-700">Ca mong muốn: {{ $customer->preferred_schedule ?: 'Chưa xác định' }} · Khai giảng: {{ $customer->desired_start_date?->format('d/m/Y') ?? 'Linh hoạt' }}</div>
                </div>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Customer Info Card -->
        <div class="space-y-6">
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
                    @if ($customer->parent_name)
                        <div class="flex justify-between py-1 border-b border-gray-50">
                            <span class="text-gray-500">Phụ huynh:</span>
                            <span class="font-medium text-gray-900">{{ $customer->parent_name }}</span>
                        </div>
                    @endif
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
                scoreL: {{ (float)($submission->listening_score ?? 6.0) }},
                scoreR: {{ (float)($submission->reading_score ?? 6.5) }},
                scoreS: {{ (float)($submission->speaking_score ?? 6.0) }},
                testLink: '{{ route('portal.test.take', ['code' => $customer->assignedTest?->code ?? 'TEST-IE-2026', 'lead_id' => $customer->id]) }}'
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
                                    <span>{{ $submission->overall_score ?? $customer->test_score }} Band</span>
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
                                    <p class="text-[11px] text-gray-500 font-mono">Mã: {{ $customer->assignedTest?->code ?? 'TEST-IE-2026' }} · {{ $customer->assignedTest?->duration_minutes ?? 45 }} phút</p>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1">
                            <a href="{{ route('portal.test.take', ['code' => $customer->assignedTest?->code ?? 'TEST-IE-2026', 'lead_id' => $customer->id]) }}" target="_blank" class="w-full py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-xs transition flex items-center justify-center gap-1.5">
                                <span class="material-symbols-outlined text-[15px]">open_in_new</span>
                                <span>Mở Cổng Test Ngay</span>
                            </a>
                            <button type="button" @click="navigator.clipboard.writeText(testLink); alert('Đã sao chép đường dẫn bài test vào clipboard!');" class="w-full py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 font-semibold text-xs rounded-xl shadow-2xs transition flex items-center justify-center gap-1.5 cursor-pointer">
                                <span class="material-symbols-outlined text-[15px]">content_copy</span>
                                <span>Sao chép Link Test</span>
                            </button>
                        </div>
                    </div>

                <!-- STATE 3: ĐÃ CÓ KẾT QUẢ & THANG ĐIỂM TỰ ĐỘNG -->
                @else
                    <div class="p-5 space-y-4">
                        <!-- Score Header Summary -->
                        <div class="p-4 bg-gradient-to-br from-amber-50 to-orange-50 border border-amber-200/80 rounded-2xl flex items-center justify-between gap-4 flex-wrap">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-xl bg-primary-container text-white flex flex-col items-center justify-center font-black shadow-sm">
                                    <span class="text-sm leading-none">{{ $submission->overall_score ?? $customer->test_score ?? '6.0' }}</span>
                                    <span class="text-[9px] uppercase tracking-wider font-semibold opacity-90">Band</span>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h4 class="font-black text-gray-900 text-sm">Điểm Đánh Giá Năng Lực</h4>
                                        <span class="px-2 py-0.5 bg-white border border-amber-300 text-amber-900 rounded-md text-[10px] font-black uppercase font-mono shadow-2xs">
                                            CEFR: {{ $submission->cefr_level ?? 'B1' }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-amber-950 font-semibold mt-0.5">
                                        Khóa đề xuất: <span class="text-primary-container font-bold">{{ $submission->recommended_course ?? ($customer->course_interest ?? 'IELTS 6.5 Intensive') }}</span>
                                    </p>
                                </div>
                            </div>
                            @if ($submission)
                                <div class="shrink-0 flex items-center gap-2">
                                    <a href="{{ \Illuminate\Support\Facades\URL::signedRoute('portal.test.scorecard', ['id' => $submission->id]) }}" target="_blank" class="px-3.5 py-2 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-xs">
                                        <span class="material-symbols-outlined text-[15px] text-amber-400">military_tech</span>
                                        <span>Xem Scorecard</span>
                                    </a>
                                </div>
                            @endif
                        </div>

                        <!-- 4 Skill Scores Details -->
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                            <!-- Listening -->
                            <div class="p-2.5 bg-indigo-50/50 rounded-xl border border-indigo-100 text-center space-y-1">
                                <span class="font-bold text-indigo-900 uppercase text-[10px] block">Nghe (Listening)</span>
                                <span class="text-lg font-black font-mono text-indigo-700 block">{{ $submission->listening_score ?? 6.0 }}</span>
                            </div>

                            <!-- Reading -->
                            <div class="p-2.5 bg-emerald-50/50 rounded-xl border border-emerald-100 text-center space-y-1">
                                <span class="font-bold text-emerald-900 uppercase text-[10px] block">Đọc (Reading)</span>
                                <span class="text-lg font-black font-mono text-emerald-700 block">{{ $submission->reading_score ?? 6.5 }}</span>
                            </div>

                            <!-- Writing -->
                            <div class="p-2.5 bg-amber-50/50 rounded-xl border border-amber-100 text-center space-y-1">
                                <span class="font-bold text-amber-900 uppercase text-[10px] block">Viết (Writing)</span>
                                <span class="text-lg font-black font-mono text-amber-700 block">{{ $submission->writing_score ?? 5.5 }}</span>
                            </div>

                            <!-- Speaking -->
                            <div class="p-2.5 bg-rose-50/50 rounded-xl border border-rose-100 text-center space-y-1">
                                <span class="font-bold text-rose-900 uppercase text-[10px] block">Nói (Speaking)</span>
                                <span class="text-lg font-black font-mono text-rose-700 block">{{ $submission->speaking_score ?? 6.0 }}</span>
                            </div>
                        </div>

                        <!-- Teacher Comments -->
                        @if ($submission?->teacher_comments)
                            <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl space-y-1 text-xs">
                                <div class="flex items-center gap-1 text-[11px] font-bold text-slate-700">
                                    <span class="material-symbols-outlined text-[14px] text-primary">rate_review</span>
                                    <span>Nhận xét của Giáo viên / Giám thị:</span>
                                </div>
                                <p class="text-gray-800 leading-relaxed italic text-[11px]">"{{ $submission->teacher_comments }}"</p>
                            </div>
                        @endif

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
                    return {
                        hasResult: cfg.hasResult,
                        hasScheduled: cfg.hasScheduled,
                        khoiKey: 'khoi2_3',
                        scoreL: cfg.scoreL,
                        scoreR: cfg.scoreR,
                        scoreS: cfg.scoreS,
                        maxL: 15, maxR: 15, maxS: 10,
                        totalScore: 0,
                        placementCourse: 'STARTERS (FAM 1 _ UNIT 7 - 12)',
                        generatedComment: '',
                        testLink: cfg.testLink,

                        init() {
                            this.recalc();
                        },

                        recalc() {
                            const RUBRICS = {
                                khoi1_2: {
                                    maxL: 10, maxR: 15, maxS: 10,
                                    listening: s => s>=8 ? "Con nghe tốt, nắm được từ vựng các chủ đề đời sống." : (s>=5 ? "Đã có kĩ năng nghe cơ bản, nhận diện từ qua tranh." : "Bắt đầu hình thành kĩ năng nghe cơ bản."),
                                    reading: s => s>=11 ? "Vốn từ cơ bản tốt, nhớ chính tả, hiểu câu đơn." : (s>=6 ? "Nhận diện từ vựng tốt, cần củng cố chính tả." : "Chưa có nền từ tốt."),
                                    speaking: s => s>=8 ? "Nói trôi chảy, phản xạ nhanh với câu hỏi Starters." : (s>=5 ? "Phát âm rõ ràng, nhận diện câu hỏi cơ bản." : "Chưa hình thành kĩ năng nghe nói cơ bản."),
                                    placement: t => t<10 ? "PRE STARTERS (FAM 0)" : (t<=15 ? "STARTERS (FAM 1 _ BÀI ĐẦU)" : (t<=25 ? "STARTERS (FAM 1 _ BÀI 5 - 10)" : "STARTERS (FAM 1 _ NÂNG CAO)"))
                                },
                                khoi2_3: {
                                    maxL: 15, maxR: 15, maxS: 10,
                                    listening: s => s>=11 ? "Nghe khá, phân biệt được thông tin gây nhiễu." : (s>=6 ? "Nghe trung bình khá, nhận diện thông tin 1 chiều." : "Nghe cơ bản, chưa quen bài nghe đa dạng."),
                                    reading: s => s>=11 ? "Nền từ khá tốt, đọc hiểu câu cơ bản linh hoạt." : (s>=6 ? "Nhận diện cơ bản từ vựng, cần trau dồi sắp xếp câu." : "Cần củng cố ngữ pháp và vốn từ vựng."),
                                    speaking: s => s>=8 ? "Nói trôi chảy, tự tin trả lời câu dài." : (s>=5 ? "Có kĩ năng nghe nói cơ bản, phát âm tương đối rõ." : "Chưa hình thành kĩ năng giao tiếp cơ bản."),
                                    placement: t => t<20 ? "PRE STARTERS _ FAM 1 (DƯỚI U5)" : (t<=30 ? "STARTERS (FAM 1 _ UNIT 6 - 10)" : "STARTERS (FAM 1 _ UNIT 7 - 12)")
                                },
                                khoi3_4: {
                                    maxL: 15, maxR: 20, maxS: 10,
                                    listening: s => s>=11 ? "Nghe khá/tốt, nắm trọn vẹn nội dung Movers." : (s>=6 ? "Nghe hiểu câu ngắn 1 chiều." : "Kĩ năng nghe ở mức hình thành cơ bản."),
                                    reading: s => s>=15 ? "Nền từ vựng Movers phong phú, xử lý bài đọc tốt." : (s>=7 ? "Đọc hiểu câu ngắn và kết nối thông tin tốt." : "Nhận diện từ đơn cơ bản, ngữ pháp cần nâng cao."),
                                    speaking: s => s>=8 ? "Nói trôi chảy, mô tả tranh và so sánh chi tiết." : (s>=5 ? "Phản xạ giao tiếp tự tin, phát âm rõ ràng." : "Nghe hiểu cơ bản, nền từ còn yếu."),
                                    placement: t => t<20 ? "FAM 2 (NỬA ĐẦU)" : (t<=35 ? "FAM 2 (NỬA SAU)" : "LUYỆN THI MOVERS")
                                },
                                khoi4_5: {
                                    maxL: 15, maxR: 15, maxS: 10,
                                    listening: s => s>=11 ? "Nghe xuất sắc, xử lý nhanh các bẫy thông tin." : (s>=6 ? "Nghe hiểu tốt các hội thoại thông thường." : "Kỹ năng nghe hình thành cơ bản."),
                                    reading: s => s>=11 ? "Nền từ vựng vững vàng, đọc hiểu nhanh." : (s>=6 ? "Nắm từ vựng trọng tâm, đọc hiểu đoạn văn ngắn." : "Cần củng cố cấu trúc câu và các thì căn bản."),
                                    speaking: s => s>=8 ? "Phản xạ tự nhiên, mô tả tranh sinh động." : (s>=5 ? "Nói lưu loát, tự tin trình bày câu hoàn chỉnh." : "Giao tiếp câu đơn, cần mở rộng câu."),
                                    placement: t => t<20 ? "FAM 2 (NỬA ĐẦU)" : (t<=30 ? "FAM 2 (NỬA SAU)" : "LUYỆN THI MOVERS")
                                }
                            };

                            const r = RUBRICS[this.khoiKey];
                            this.maxL = r.maxL;
                            this.maxR = r.maxR;
                            this.maxS = r.maxS;

                            let l = parseFloat(this.scoreL) || 0;
                            let rd = parseFloat(this.scoreR) || 0;
                            let s = parseFloat(this.scoreS) || 0;

                            l = Math.min(Math.max(l, 0), r.maxL);
                            rd = Math.min(Math.max(rd, 0), r.maxR);
                            s = Math.min(Math.max(s, 0), r.maxS);

                            this.totalScore = Math.round((l + rd + s) * 10) / 10;
                            this.placementCourse = r.placement(this.totalScore);

                            this.generatedComment = 
                                `【Kỹ năng Nghe】: ${r.listening(l)}\n` +
                                `【Kỹ năng Đọc & Viết】: ${r.reading(rd)}\n` +
                                `【Kỹ năng Nói】: ${r.speaking(s)}\n` +
                                `【Đề xuất Xếp lớp】: ${this.placementCourse}`;
                        }
                    };
                }
            </script>
        </div>

        <!-- Activity Timeline & Care History -->
        <div class="lg:col-span-2 space-y-6">
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
                <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wider pb-2 border-b border-gray-100">
                    Lịch sử tương tác &amp; Tiến trình chăm sóc ({{ $customer->histories->count() }})
                </h3>

                <div class="space-y-4">
                    @forelse ($customer->histories as $history)
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
