<x-app-layout>
    <x-ui.page-header title="Trang chủ" icon="cottage" :back="route('portal.app-shell', ['student_id' => $student?->id])">
        <x-slot:actions>
            <x-ui.button icon="upload_file" :href="route('portal.student.homework', ['studentId' => $student?->id])">Nộp bài tập</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Outer Mobile Mockup Frame --}}
    <div class="max-w-[430px] mx-auto bg-background min-h-[844px] shadow-2xl rounded-3xl border border-surface-container-highest overflow-hidden flex flex-col relative pb-20 my-4" x-data>

        {{-- Portal Header --}}
        @include('portal.partials.top-header', ['student' => $student, 'students' => $students, 'title' => 'MENGLISH'])

        {{-- Main Content Area --}}
        <main class="flex-1 w-full p-4 flex flex-col gap-5 overflow-y-auto">
            {{-- Header Welcome --}}
            <div class="flex flex-col gap-1 pt-1">
                <span class="text-sm font-normal text-on-surface-variant">Xin chào,</span>
                <h1 class="text-2xl font-bold text-primary">{{ $student?->name ?? 'Học viên' }}</h1>
            </div>

            {{-- Student Info Card (Bento style) --}}
            <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest/80 p-4 flex flex-col gap-4 shadow-sm relative overflow-hidden">
                <div class="absolute top-0 right-0 w-24 h-24 bg-primary-container/5 rounded-bl-full pointer-events-none"></div>

                <div class="flex items-center justify-between border-b border-surface-container-highest pb-3">
                    <h2 class="text-base font-bold text-on-surface flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1;">person</span>
                        Thông tin học sinh
                    </h2>
                    <div class="flex items-center gap-1.5">
                        <x-ui.button variant="secondary" size="sm" icon="edit" x-on:click="$dispatch('open-modal', 'portal-edit-profile')">Sửa</x-ui.button>
                        <x-ui.badge color="success" :pill="true">
                            {{ $student?->status_label ?? '—' }}
                        </x-ui.badge>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-y-3 gap-x-3 text-xs">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[10px] text-on-surface-variant/70 uppercase tracking-wider font-bold">Ngày sinh</span>
                        <span class="font-medium text-on-surface">{{ $student?->dob ? $student->dob->format('d/m/Y') : '—' }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[10px] text-on-surface-variant/70 uppercase tracking-wider font-bold">Lớp đang học</span>
                        <span class="font-bold text-secondary">{{ $studentClasses->isNotEmpty() ? $studentClasses->pluck('name')->implode(', ') : 'Chưa xếp lớp' }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[10px] text-on-surface-variant/70 uppercase tracking-wider font-bold">Giáo viên chính</span>
                        <span class="font-medium text-on-surface flex items-center gap-1">
                            {{ $student?->currentClass?->teacher?->name ?? '—' }}
                        </span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[10px] text-on-surface-variant/70 uppercase tracking-wider font-bold">Số điện thoại</span>
                        <span class="font-mono font-medium text-on-surface">{{ $student?->phone ?? '—' }}</span>
                    </div>
                    <div class="col-span-2 flex flex-col gap-0.5 border-t border-surface-container-highest pt-2">
                        <span class="text-[10px] text-on-surface-variant/70 uppercase tracking-wider font-bold">Địa chỉ</span>
                        <span class="text-on-surface-variant text-[12px]">{{ $student?->address ?? '—' }}</span>
                    </div>
                    @if($student?->notes)
                    <div class="col-span-2 flex flex-col gap-0.5 bg-warning-container p-2 rounded-lg border border-warning/30">
                        <span class="text-[10px] text-warning uppercase tracking-wider font-bold">Ghi chú</span>
                        <span class="text-on-surface-variant text-[11px]">{{ $student->notes }}</span>
                    </div>
                    @endif
                </div>
            </div>

            <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-4 shadow-sm">
                <h2 class="text-sm font-bold text-on-surface mb-3">Tiến độ học tập đã duyệt</h2>
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="bg-tertiary/10 rounded-xl p-2"><div class="text-lg font-black text-tertiary">{{ $learningProgress['attendance_present'] }}/{{ $learningProgress['attendance_total'] }}</div><div class="text-[10px] text-on-surface-variant">Chuyên cần</div></div>
                    <div class="bg-secondary/10 rounded-xl p-2"><div class="text-lg font-black text-secondary">{{ $learningProgress['homework_submitted'] }}/{{ $learningProgress['homework_total'] }}</div><div class="text-[10px] text-on-surface-variant">Bài tập</div></div>
                    <div class="bg-primary-container/10 rounded-xl p-2"><div class="text-lg font-black text-primary">{{ $learningProgress['latest_big_test']?->overall_score ?? '—' }}</div><div class="text-[10px] text-on-surface-variant">Big Test mới nhất</div></div>
                </div>
            </div>

            {{-- Lịch học sắp tới (buổi học thật của các lớp + buổi phụ đạo) --}}
            <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-4 shadow-sm" data-section="upcoming-schedule">
                <h2 class="text-sm font-bold text-on-surface mb-3 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-primary text-[18px]">calendar_month</span>
                    Lịch học sắp tới
                </h2>
                <div class="flex flex-col gap-2">
                    @forelse($upcomingSessions as $s)
                        @php $cancelled = $s->status === 'cancelled'; @endphp
                        <div class="flex items-center justify-between gap-2 rounded-xl border px-3 py-2 text-xs {{ $cancelled ? 'border-surface-container-highest bg-surface-container-low text-on-surface-variant/70' : 'border-surface-container-highest' }}">
                            <div>
                                <div class="font-bold {{ $cancelled ? 'line-through' : 'text-on-surface' }}">{{ ['', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'][$s->date->isoWeekday()] }}, {{ $s->date->format('d/m') }} · {{ $s->start_time?->format('H:i') }}-{{ $s->end_time?->format('H:i') }}</div>
                                <div class="text-[11px] text-on-surface-variant">{{ $s->classModel?->name }}@if($s->room) · Phòng {{ $s->room }}@endif</div>
                            </div>
                            <x-ui.badge :color="$cancelled ? 'neutral' : ($s->type === 'regular' ? 'success' : 'secondary')" :pill="true">
                                {{ $cancelled ? 'Nghỉ' : ($s->type === 'makeup' ? 'Học bù' : ($s->type === 'support' ? 'Phụ đạo' : 'Buổi học')) }}
                            </x-ui.badge>
                        </div>
                    @empty
                        <p class="text-xs text-on-surface-variant">Chưa có buổi học nào trong {{ 14 }} ngày tới.</p>
                    @endforelse
                </div>
            </div>

            {{-- Điểm danh gần đây --}}
            <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-4 shadow-sm" data-section="attendance-history">
                <h2 class="text-sm font-bold text-on-surface mb-3 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-primary text-[18px]">fact_check</span>
                    Lịch sử điểm danh
                </h2>
                <div class="divide-y divide-surface-container-highest">
                    @forelse($attendanceHistory as $a)
                        <div class="flex items-center justify-between py-2 text-xs">
                            <div>
                                <div class="font-semibold text-on-surface">{{ ($a->classSession?->date ?? $a->session_date)?->format('d/m/Y') }}</div>
                                <div class="text-[11px] text-on-surface-variant">{{ $a->classModel?->name }}@if($a->note) · {{ $a->note }}@endif</div>
                            </div>
                            <x-ui.badge :color="in_array($a->status, ['present', 'late'], true) ? 'success' : 'error'" :pill="true">{{ $a->status_label }}</x-ui.badge>
                        </div>
                    @empty
                        <p class="text-xs text-on-surface-variant">Chưa có dữ liệu điểm danh.</p>
                    @endforelse
                </div>
            </div>

            {{-- Kết quả Big Test (đã duyệt / đã gửi phụ huynh) --}}
            <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-4 shadow-sm" data-section="big-test-results">
                <h2 class="text-sm font-bold text-on-surface mb-3 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-primary text-[18px]">workspace_premium</span>
                    Kết quả Big Test
                </h2>
                <div class="flex flex-col gap-2">
                    @forelse($bigTestResults as $r)
                        <div class="rounded-xl border border-surface-container-highest px-3 py-2 text-xs">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-on-surface">{{ $r->bigTest?->title ?? 'Big Test' }}</span>
                                <span class="font-black font-mono text-primary">{{ $r->is_absent ? 'Vắng thi' : $r->overall_score }}</span>
                            </div>
                            @unless($r->is_absent)
                                <div class="mt-1 grid grid-cols-4 gap-1 text-[10px] text-on-surface-variant text-center">
                                    <span>Nghe {{ $r->listening_score ?? '—' }}</span><span>Đọc {{ $r->reading_score ?? '—' }}</span><span>Viết {{ $r->writing_score ?? '—' }}</span><span>Nói {{ $r->speaking_score ?? '—' }}</span>
                                </div>
                            @endunless
                            @if($r->progress_note)
                                <p class="mt-1 text-[11px] text-on-surface-variant">{{ $r->progress_note }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-xs text-on-surface-variant">Chưa có kết quả Big Test đã duyệt.</p>
                    @endforelse
                </div>
            </div>

            {{-- Tuition Info Card (Glassmorphism inspired) --}}
            <div class="bg-gradient-to-br from-primary-container to-primary text-white rounded-2xl p-4 flex flex-col gap-4 shadow-lg relative overflow-hidden">
                {{-- Decorative background elements --}}
                <div class="absolute top-[-20%] right-[-10%] w-32 h-32 bg-surface-container-lowest/15 rounded-full blur-2xl pointer-events-none"></div>
                <div class="absolute bottom-[-20%] left-[-10%] w-24 h-24 bg-black/10 rounded-full blur-xl pointer-events-none"></div>

                <div class="flex items-center justify-between relative z-10">
                    <h2 class="text-base font-bold flex items-center gap-1.5 text-white">
                        <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">account_balance_wallet</span>
                        Thông tin học phí
                    </h2>
                    <div class="flex items-center gap-1.5">
                        <button type="button"
                                @click="$dispatch('open-modal', 'portal-tuition-request')"
                                class="text-white bg-surface-container-lowest/20 hover:bg-surface-container-lowest/30 transition-colors px-2.5 py-1 rounded-full text-[11px] font-semibold flex items-center gap-1 backdrop-blur-xs active:scale-95">
                            <span class="material-symbols-outlined text-[13px]">send</span> Báo đóng
                        </button>
                        <button type="button"
                                @click="$dispatch('open-modal', 'portal-tuition-history')"
                                class="text-white bg-surface-container-lowest/20 hover:bg-surface-container-lowest/30 transition-colors px-2.5 py-1 rounded-full text-[11px] font-semibold flex items-center gap-1 backdrop-blur-xs active:scale-95">
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

                <div class="flex items-center justify-between relative z-10 bg-surface-container-lowest/10 px-3 py-2 rounded-xl text-xs">
                    <span class="text-white/90 font-medium">Dự kiến khóa tới:</span>
                    <span class="font-bold font-mono text-white">{{ number_format($nextTermFee, 0, ',', '.') }}đ</span>
                </div>
            </div>

            {{-- Quick Action Cards to Other Steps --}}
            <div class="grid grid-cols-2 gap-3">
                <a href="{{ route('portal.student.homework', ['studentId' => $student?->id]) }}" class="bg-surface-container-lowest p-3 rounded-xl border border-surface-container-highest hover:border-primary-container transition shadow-2xs flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-[20px]">upload_file</span>
                    </div>
                    <div class="overflow-hidden">
                        <span class="text-xs font-bold text-on-surface block truncate">Nộp bài tập</span>
                        <span class="text-[10px] text-on-surface-variant/70 block">Video & bài viết</span>
                    </div>
                </a>

                <a href="{{ route('portal.student.pronunciation', ['studentId' => $student?->id]) }}" class="bg-surface-container-lowest p-3 rounded-xl border border-surface-container-highest hover:border-primary-container transition shadow-2xs flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-error/10 text-error flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-[20px]">mic</span>
                    </div>
                    <div class="overflow-hidden">
                        <span class="text-xs font-bold text-on-surface block truncate">Luyện phát âm</span>
                        <span class="text-[10px] text-on-surface-variant/70 block">Thu âm AI</span>
                    </div>
                </a>
            </div>
        </main>

        {{-- Bottom Sheet: Lịch sử thu học phí --}}
        <x-ui.modal name="portal-tuition-history" title="Lịch sử thu học phí" max-width="md">
            {{-- List Content --}}
            <div class="flex flex-col gap-3">
                <div class="text-[11px] font-semibold text-on-surface-variant mb-1 flex items-center gap-1">
                    <span class="material-symbols-outlined text-[14px]">filter_list</span> Chỉ hiển thị phiếu "Đã duyệt"
                </div>

                @foreach($receipts as $rc)
                    <div class="bg-surface-container-low border border-surface-container-highest/80 rounded-xl p-3 flex flex-col gap-2 shadow-2xs">
                        <div class="flex justify-between items-start">
                            <div class="flex flex-col">
                                <span class="text-[11px] font-bold text-primary font-mono">{{ $rc->receipt_number ?? ('PT-' . $rc->id) }}</span>
                                <span class="text-xs font-semibold text-on-surface">{{ $rc->title ?? trim('Học phí ' . ($student?->currentClass?->name ?? '')) }}</span>
                            </div>
                            <x-ui.badge color="success" :pill="true" :dot="false">
                                <span class="material-symbols-outlined text-[12px]">check_circle</span> Đã duyệt
                            </x-ui.badge>
                        </div>
                        <div class="flex justify-between items-end border-t border-surface-container-highest pt-2 mt-1 text-xs">
                            <div class="flex flex-col gap-0.5 text-on-surface-variant text-[11px]">
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[13px]">calendar_today</span> {{ is_string($rc->payment_date) ? $rc->payment_date : ($rc->payment_date?->format('d/m/Y') ?? '—') }}
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[13px]">payments</span> {{ $rc->payment_method ?? '—' }}
                                </span>
                            </div>
                            <x-ui.money :value="(float) $rc->amount" class="font-bold" />
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.modal>

        {{-- Modal Cập nhật thông tin học sinh (CRUD UPDATE) --}}
        <x-ui.modal name="portal-edit-profile" title="Cập nhật thông tin học viên" max-width="sm">
            <form id="portal-edit-profile-form" action="{{ route('portal.student.profile.update', $student?->id ?? 0) }}" method="POST" class="space-y-3">
                @csrf
                <x-ui.input name="phone" label="Số điện thoại liên hệ" :value="$student?->phone" required />
                <x-ui.input name="address" label="Địa chỉ" :value="$student?->address" />
                <x-ui.textarea name="notes" label="Ghi chú cho trung tâm / giáo viên" rows="3" :value="$student?->notes"
                               placeholder="Ví dụ: Bé hay dị ứng phấn, xin phép vào muộn 5p..." />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'portal-edit-profile')">
                    Hủy
                </x-ui.button>
                <x-ui.button type="submit" form="portal-edit-profile-form">
                    Lưu thay đổi
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        {{-- Modal Báo đã nộp học phí / Yêu cầu hỗ trợ (CRUD CREATE) --}}
        <x-ui.modal name="portal-tuition-request" title="Báo đóng học phí" max-width="sm">
            <form id="portal-tuition-request-form" action="{{ route('portal.student.tuition.request') }}" method="POST" class="space-y-3">
                @csrf
                <input type="hidden" name="student_id" value="{{ $student?->id }}">
                <x-ui.input type="number" name="amount" label="Số tiền đã chuyển (VNĐ)" :value="$debtAmount > 0 ? (int) $debtAmount : ''" min="1000" required class="font-mono font-bold" />
                <x-ui.textarea name="content" label="Nội dung chuyển khoản / Ghi chú" rows="3" required
                               placeholder="Nhập mã giao dịch ngân hàng hoặc nội dung chuyển tiền..." />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'portal-tuition-request')">
                    Hủy
                </x-ui.button>
                <x-ui.button type="submit" form="portal-tuition-request-form">
                    Gửi xác nhận
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        {{-- Bottom Navigation Bar Component --}}
        @include('portal.partials.bottom-nav', ['activeTab' => 'home', 'student' => $student])
    </div>
</x-app-layout>
