<x-app-layout>
    <x-ui.page-header title="Trang chủ" icon="cottage" :back="route('portal.app-shell', ['student_id' => $student?->id])">
        <x-slot:actions>
            <x-ui.button icon="upload_file" :href="route('portal.student.homework', ['studentId' => $student?->id])">Nộp bài tập</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Outer Mobile Mockup Frame --}}
    <div class="max-w-[430px] mx-auto bg-background min-h-[844px] shadow-2xl rounded-3xl border border-gray-200 overflow-hidden flex flex-col relative pb-20 my-4"
         x-data="{ historyOpen: false, editProfileOpen: false, tuitionReqOpen: false }">

        {{-- Portal Header --}}
        @include('portal.partials.top-header', ['student' => $student, 'students' => $students, 'title' => 'MENGLISH'])

        {{-- Main Content Area --}}
        <main class="flex-1 w-full p-4 flex flex-col gap-5 overflow-y-auto">
            {{-- Header Welcome --}}
            <div class="flex flex-col gap-1 pt-1">
                <span class="text-sm font-normal text-gray-600">Xin chào,</span>
                <h1 class="text-2xl font-bold text-primary">{{ $student?->name ?? 'Học viên' }}</h1>
            </div>

            {{-- Student Info Card (Bento style) --}}
            <div class="bg-white rounded-2xl border border-gray-200/80 p-4 flex flex-col gap-4 shadow-sm relative overflow-hidden">
                <div class="absolute top-0 right-0 w-24 h-24 bg-primary-container/5 rounded-bl-full pointer-events-none"></div>

                <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                    <h2 class="text-base font-bold text-gray-900 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1;">person</span>
                        Thông tin học sinh
                    </h2>
                    <div class="flex items-center gap-1.5">
                        <button type="button" @click="editProfileOpen = true" class="px-2 py-0.5 bg-orange-50 text-primary hover:bg-orange-100 rounded-full text-[11px] font-bold border border-orange-200 flex items-center gap-0.5 transition">
                            <span class="material-symbols-outlined text-[13px]">edit</span> Sửa
                        </button>
                        <span class="px-2.5 py-0.5 bg-emerald-50 text-emerald-700 rounded-full text-[11px] font-bold border border-emerald-200">
                            {{ $student?->status_label ?? '—' }}
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-y-3 gap-x-3 text-xs">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[10px] text-gray-400 uppercase tracking-wider font-bold">Ngày sinh</span>
                        <span class="font-medium text-gray-800">{{ $student?->dob ? $student->dob->format('d/m/Y') : '—' }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[10px] text-gray-400 uppercase tracking-wider font-bold">Lớp đang học</span>
                        <span class="font-bold text-secondary">{{ $studentClasses->isNotEmpty() ? $studentClasses->pluck('name')->implode(', ') : 'Chưa xếp lớp' }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[10px] text-gray-400 uppercase tracking-wider font-bold">Giáo viên chính</span>
                        <span class="font-medium text-gray-800 flex items-center gap-1">
                            {{ $student?->currentClass?->teacher?->name ?? '—' }}
                        </span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[10px] text-gray-400 uppercase tracking-wider font-bold">Số điện thoại</span>
                        <span class="font-mono font-medium text-gray-800">{{ $student?->phone ?? '—' }}</span>
                    </div>
                    <div class="col-span-2 flex flex-col gap-0.5 border-t border-gray-50 pt-2">
                        <span class="text-[10px] text-gray-400 uppercase tracking-wider font-bold">Địa chỉ</span>
                        <span class="text-gray-700 text-[12px]">{{ $student?->address ?? '—' }}</span>
                    </div>
                    @if($student?->notes)
                    <div class="col-span-2 flex flex-col gap-0.5 bg-amber-50 p-2 rounded-lg border border-amber-200/60">
                        <span class="text-[10px] text-amber-700 uppercase tracking-wider font-bold">Ghi chú</span>
                        <span class="text-gray-700 text-[11px]">{{ $student->notes }}</span>
                    </div>
                    @endif
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm">
                <h2 class="text-sm font-bold text-gray-900 mb-3">Tiến độ học tập đã duyệt</h2>
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="bg-emerald-50 rounded-xl p-2"><div class="text-lg font-black text-emerald-700">{{ $learningProgress['attendance_present'] }}/{{ $learningProgress['attendance_total'] }}</div><div class="text-[10px] text-gray-500">Chuyên cần</div></div>
                    <div class="bg-blue-50 rounded-xl p-2"><div class="text-lg font-black text-blue-700">{{ $learningProgress['homework_submitted'] }}/{{ $learningProgress['homework_total'] }}</div><div class="text-[10px] text-gray-500">Bài tập</div></div>
                    <div class="bg-purple-50 rounded-xl p-2"><div class="text-lg font-black text-purple-700">{{ $learningProgress['latest_big_test']?->overall_score ?? '—' }}</div><div class="text-[10px] text-gray-500">Big Test mới nhất</div></div>
                </div>
            </div>

            {{-- Lịch học sắp tới (buổi học thật của các lớp + buổi phụ đạo) --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm" data-section="upcoming-schedule">
                <h2 class="text-sm font-bold text-gray-900 mb-3 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-primary text-[18px]">calendar_month</span>
                    Lịch học sắp tới
                </h2>
                <div class="flex flex-col gap-2">
                    @forelse($upcomingSessions as $s)
                        @php $cancelled = $s->status === 'cancelled'; @endphp
                        <div class="flex items-center justify-between gap-2 rounded-xl border px-3 py-2 text-xs {{ $cancelled ? 'border-gray-200 bg-gray-50 text-gray-400' : 'border-gray-100' }}">
                            <div>
                                <div class="font-bold {{ $cancelled ? 'line-through' : 'text-gray-900' }}">{{ ['', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'][$s->date->isoWeekday()] }}, {{ $s->date->format('d/m') }} · {{ $s->start_time?->format('H:i') }}-{{ $s->end_time?->format('H:i') }}</div>
                                <div class="text-[11px] text-gray-500">{{ $s->classModel?->name }}@if($s->room) · Phòng {{ $s->room }}@endif</div>
                            </div>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full {{ $cancelled ? 'bg-gray-100 text-gray-500' : ($s->type === 'regular' ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700') }}">
                                {{ $cancelled ? 'Nghỉ' : ($s->type === 'makeup' ? 'Học bù' : ($s->type === 'support' ? 'Phụ đạo' : 'Buổi học')) }}
                            </span>
                        </div>
                    @empty
                        <p class="text-xs text-gray-500">Chưa có buổi học nào trong {{ 14 }} ngày tới.</p>
                    @endforelse
                </div>
            </div>

            {{-- Điểm danh gần đây --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm" data-section="attendance-history">
                <h2 class="text-sm font-bold text-gray-900 mb-3 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-primary text-[18px]">fact_check</span>
                    Lịch sử điểm danh
                </h2>
                <div class="divide-y divide-gray-100">
                    @forelse($attendanceHistory as $a)
                        <div class="flex items-center justify-between py-2 text-xs">
                            <div>
                                <div class="font-semibold text-gray-900">{{ ($a->classSession?->date ?? $a->session_date)?->format('d/m/Y') }}</div>
                                <div class="text-[11px] text-gray-500">{{ $a->classModel?->name }}@if($a->note) · {{ $a->note }}@endif</div>
                            </div>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full {{ in_array($a->status, ['present', 'late'], true) ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">{{ $a->status_label }}</span>
                        </div>
                    @empty
                        <p class="text-xs text-gray-500">Chưa có dữ liệu điểm danh.</p>
                    @endforelse
                </div>
            </div>

            {{-- Kết quả Big Test (đã duyệt / đã gửi phụ huynh) --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm" data-section="big-test-results">
                <h2 class="text-sm font-bold text-gray-900 mb-3 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-primary text-[18px]">workspace_premium</span>
                    Kết quả Big Test
                </h2>
                <div class="flex flex-col gap-2">
                    @forelse($bigTestResults as $r)
                        <div class="rounded-xl border border-gray-100 px-3 py-2 text-xs">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-gray-900">{{ $r->bigTest?->title ?? 'Big Test' }}</span>
                                <span class="font-black font-mono text-primary">{{ $r->is_absent ? 'Vắng thi' : $r->overall_score }}</span>
                            </div>
                            @unless($r->is_absent)
                                <div class="mt-1 grid grid-cols-4 gap-1 text-[10px] text-gray-500 text-center">
                                    <span>Nghe {{ $r->listening_score ?? '—' }}</span><span>Đọc {{ $r->reading_score ?? '—' }}</span><span>Viết {{ $r->writing_score ?? '—' }}</span><span>Nói {{ $r->speaking_score ?? '—' }}</span>
                                </div>
                            @endunless
                            @if($r->progress_note)
                                <p class="mt-1 text-[11px] text-gray-600">{{ $r->progress_note }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-xs text-gray-500">Chưa có kết quả Big Test đã duyệt.</p>
                    @endforelse
                </div>
            </div>

            {{-- Tuition Info Card (Glassmorphism inspired) --}}
            <div class="bg-gradient-to-br from-primary-container to-primary text-white rounded-2xl p-4 flex flex-col gap-4 shadow-lg relative overflow-hidden">
                {{-- Decorative background elements --}}
                <div class="absolute top-[-20%] right-[-10%] w-32 h-32 bg-white/15 rounded-full blur-2xl pointer-events-none"></div>
                <div class="absolute bottom-[-20%] left-[-10%] w-24 h-24 bg-black/10 rounded-full blur-xl pointer-events-none"></div>

                <div class="flex items-center justify-between relative z-10">
                    <h2 class="text-base font-bold flex items-center gap-1.5 text-white">
                        <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">account_balance_wallet</span>
                        Thông tin học phí
                    </h2>
                    <div class="flex items-center gap-1.5">
                        <button type="button"
                                @click="tuitionReqOpen = true"
                                class="text-white bg-white/20 hover:bg-white/30 transition-colors px-2.5 py-1 rounded-full text-[11px] font-semibold flex items-center gap-1 backdrop-blur-xs active:scale-95">
                            <span class="material-symbols-outlined text-[13px]">send</span> Báo đóng
                        </button>
                        <button type="button"
                                @click="historyOpen = true"
                                class="text-white bg-white/20 hover:bg-white/30 transition-colors px-2.5 py-1 rounded-full text-[11px] font-semibold flex items-center gap-1 backdrop-blur-xs active:scale-95">
                            Lịch sử <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 relative z-10 bg-black/15 p-3 rounded-xl backdrop-blur-xs border border-white/10">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[10px] text-white/80 uppercase tracking-wider font-semibold">Tổng đã đóng</span>
                        <span class="text-xl font-bold font-mono">{{ number_format($totalPaid, 0, ',', '.') }}đ</span>
                    </div>
                    <div class="flex flex-col gap-0.5 pl-3 border-l border-white/20">
                        <span class="text-[10px] text-white/80 uppercase tracking-wider font-semibold">Còn nợ</span>
                        <span class="text-lg font-bold font-mono text-error-container">{{ number_format($debtAmount, 0, ',', '.') }}đ</span>
                    </div>
                </div>

                <div class="flex items-center justify-between relative z-10 bg-white/10 px-3 py-2 rounded-xl text-xs">
                    <span class="text-white/90 font-medium">Dự kiến khóa tới:</span>
                    <span class="font-bold font-mono text-white">{{ number_format($nextTermFee, 0, ',', '.') }}đ</span>
                </div>
            </div>

            {{-- Quick Action Cards to Other Steps --}}
            <div class="grid grid-cols-2 gap-3">
                <a href="{{ route('portal.student.homework', ['studentId' => $student?->id]) }}" class="bg-white p-3 rounded-xl border border-gray-200 hover:border-primary-container transition shadow-2xs flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-orange-50 text-primary flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-[20px]">upload_file</span>
                    </div>
                    <div class="overflow-hidden">
                        <span class="text-xs font-bold text-gray-900 block truncate">Nộp bài tập</span>
                        <span class="text-[10px] text-gray-400 block">Video & bài viết</span>
                    </div>
                </a>

                <a href="{{ route('portal.student.pronunciation', ['studentId' => $student?->id]) }}" class="bg-white p-3 rounded-xl border border-gray-200 hover:border-primary-container transition shadow-2xs flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-[20px]">mic</span>
                    </div>
                    <div class="overflow-hidden">
                        <span class="text-xs font-bold text-gray-900 block truncate">Luyện phát âm</span>
                        <span class="text-[10px] text-gray-400 block">Thu âm AI</span>
                    </div>
                </a>
            </div>
        </main>

        {{-- Bottom Sheet: Lịch sử thu học phí --}}
        <div x-show="historyOpen"
             x-cloak
             class="fixed inset-0 bg-black/40 z-[100] backdrop-blur-xs flex items-end justify-center transition-opacity"
             @click="historyOpen = false">
            <div class="w-full max-w-[430px] bg-white rounded-t-[28px] shadow-2xl flex flex-col max-h-[750px] overflow-hidden"
                 @click.stop>
                {{-- Drag Handle & Header --}}
                <div class="flex flex-col items-center pt-2.5 pb-3 border-b border-gray-100 px-4 sticky top-0 bg-white rounded-t-[28px] z-10">
                    <div class="w-12 h-1 bg-gray-300 rounded-full mb-2"></div>
                    <div class="w-full flex justify-between items-center">
                        <h3 class="text-base font-bold text-gray-900">Lịch sử thu học phí</h3>
                        <button type="button"
                                @click="historyOpen = false"
                                class="p-1 rounded-full hover:bg-gray-100 text-gray-500 transition-colors">
                            <span class="material-symbols-outlined text-[20px]">close</span>
                        </button>
                    </div>
                </div>

                {{-- List Content --}}
                <div class="overflow-y-auto p-4 flex flex-col gap-3 pb-24">
                    <div class="text-[11px] font-semibold text-gray-500 mb-1 flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]">filter_list</span> Chỉ hiển thị phiếu "Đã duyệt"
                    </div>

                    @foreach($receipts as $rc)
                        <div class="bg-gray-50 border border-gray-200/80 rounded-xl p-3 flex flex-col gap-2 shadow-2xs">
                            <div class="flex justify-between items-start">
                                <div class="flex flex-col">
                                    <span class="text-[11px] font-bold text-primary font-mono">{{ $rc->receipt_number ?? ('PT-' . $rc->id) }}</span>
                                    <span class="text-xs font-semibold text-gray-900">{{ $rc->title ?? trim('Học phí ' . ($student?->currentClass?->name ?? '')) }}</span>
                                </div>
                                <div class="px-2 py-0.5 bg-emerald-50 text-emerald-700 rounded-full text-[10px] font-bold flex items-center gap-1 border border-emerald-200">
                                    <span class="material-symbols-outlined text-[12px]">check_circle</span> Đã duyệt
                                </div>
                            </div>
                            <div class="flex justify-between items-end border-t border-gray-200 pt-2 mt-1 text-xs">
                                <div class="flex flex-col gap-0.5 text-gray-500 text-[11px]">
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[13px]">calendar_today</span> {{ is_string($rc->payment_date) ? $rc->payment_date : ($rc->payment_date?->format('d/m/Y') ?? '—') }}
                                    </span>
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[13px]">payments</span> {{ $rc->payment_method ?? '—' }}
                                    </span>
                                </div>
                                <span class="text-sm font-bold font-mono text-gray-900">{{ number_format((float) $rc->amount, 0, ',', '.') }}đ</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Modal Cập nhật thông tin học sinh (CRUD UPDATE) --}}
        <div x-show="editProfileOpen"
             x-cloak
             class="fixed inset-0 bg-black/40 z-[110] backdrop-blur-xs flex items-center justify-center p-4"
             @click="editProfileOpen = false">
            <div class="w-full max-w-[380px] bg-white rounded-2xl shadow-2xl p-5 space-y-4" @click.stop>
                <div class="flex items-center justify-between border-b pb-3">
                    <h3 class="text-sm font-bold text-gray-900 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-[18px]">edit</span>
                        Cập nhật thông tin học viên
                    </h3>
                    <button type="button" @click="editProfileOpen = false" class="text-gray-400 hover:text-gray-600">
                        <span class="material-symbols-outlined text-[20px]">close</span>
                    </button>
                </div>
                <form action="{{ route('portal.student.profile.update', $student?->id ?? 0) }}" method="POST" class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Số điện thoại liên hệ</label>
                        <input type="text" name="phone" value="{{ $student?->phone }}" required
                               class="w-full px-3 py-2 text-xs border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Địa chỉ</label>
                        <input type="text" name="address" value="{{ $student?->address }}"
                               class="w-full px-3 py-2 text-xs border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Ghi chú cho trung tâm / giáo viên</label>
                        <textarea name="notes" rows="3" placeholder="Ví dụ: Bé hay dị ứng phấn, xin phép vào muộn 5p..."
                                  class="w-full px-3 py-2 text-xs border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container">{{ $student?->notes }}</textarea>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="editProfileOpen = false" class="px-3 py-2 rounded-xl bg-gray-100 text-gray-700 text-xs font-semibold hover:bg-gray-200 transition">
                            Hủy
                        </button>
                        <button type="submit" class="px-4 py-2 rounded-xl bg-primary-container text-white text-xs font-bold hover:bg-primary-dark transition shadow-sm">
                            Lưu thay đổi
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Modal Báo đã nộp học phí / Yêu cầu hỗ trợ (CRUD CREATE) --}}
        <div x-show="tuitionReqOpen"
             x-cloak
             class="fixed inset-0 bg-black/40 z-[110] backdrop-blur-xs flex items-center justify-center p-4"
             @click="tuitionReqOpen = false">
            <div class="w-full max-w-[380px] bg-white rounded-2xl shadow-2xl p-5 space-y-4" @click.stop>
                <div class="flex items-center justify-between border-b pb-3">
                    <h3 class="text-sm font-bold text-gray-900 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-[18px]">payments</span>
                        Báo đóng học phí
                    </h3>
                    <button type="button" @click="tuitionReqOpen = false" class="text-gray-400 hover:text-gray-600">
                        <span class="material-symbols-outlined text-[20px]">close</span>
                    </button>
                </div>
                <form action="{{ route('portal.student.tuition.request') }}" method="POST" class="space-y-3">
                    @csrf
                    <input type="hidden" name="student_id" value="{{ $student?->id }}">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Số tiền đã chuyển (VNĐ)</label>
                        <input type="number" name="amount" value="{{ $debtAmount > 0 ? (int) $debtAmount : '' }}" min="1000" required
                               class="w-full px-3 py-2 text-xs font-bold font-mono border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Nội dung chuyển khoản / Ghi chú</label>
                        <textarea name="content" rows="3" placeholder="Nhập mã giao dịch ngân hàng hoặc nội dung chuyển tiền..." required
                                  class="w-full px-3 py-2 text-xs border border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container"></textarea>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="tuitionReqOpen = false" class="px-3 py-2 rounded-xl bg-gray-100 text-gray-700 text-xs font-semibold hover:bg-gray-200 transition">
                            Hủy
                        </button>
                        <button type="submit" class="px-4 py-2 rounded-xl bg-primary-container text-white text-xs font-bold hover:bg-primary-dark transition shadow-sm">
                            Gửi xác nhận
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Bottom Navigation Bar Component --}}
        @include('portal.partials.bottom-nav', ['activeTab' => 'home', 'student' => $student])
    </div>
</x-app-layout>
