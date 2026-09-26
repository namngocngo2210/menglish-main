<x-app-layout>
    <x-ui.page-header title="Cổng Phụ huynh / Học sinh" icon="smartphone" :back="auth()->user()?->can('system_category.manage') ? route('academic-system.index', ['cat' => '04_Cong_Phu_Huynh_Hoc_Sinh']) : null">
        <x-slot:actions>
            <x-ui.button icon="cottage" :href="route('portal.student.home', ['studentId' => $student?->id])">Vào Trang chủ</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Outer Mobile Shell Container --}}
    <div class="max-w-[430px] mx-auto bg-surface-container-lowest min-h-[844px] shadow-2xl rounded-3xl border border-surface-container-highest overflow-hidden flex flex-col relative pb-20 my-4">
        {{-- Top App Bar --}}
        <header class="w-full top-0 sticky bg-background dark:bg-inverse-surface border-b border-surface-container-highest dark:border-inverse-surface flex items-center justify-between px-4 h-16 z-40">
            <h1 class="font-bold text-2xl text-primary tracking-tight">MENGLISH</h1>
            <div class="flex items-center gap-2">
                @if($students && $students->count() > 1)
                    <x-ui.select class="!min-w-0 py-1 text-[11px] font-semibold" aria-label="Chọn học viên"
                                 onchange="window.location.href = '{{ route('portal.app-shell') }}?student_id=' + this.value"
                                 :options="$students->pluck('name', 'id')" :value="$student?->id" />
                @endif
                <a href="{{ route('portal.student.home', ['studentId' => $student?->id]) }}" class="w-10 h-10 rounded-full bg-primary-container/10 flex items-center justify-center overflow-hidden hover:opacity-80 transition-opacity active:scale-95 duration-100 text-primary">
                    <span class="material-symbols-outlined text-2xl">account_circle</span>
                </a>
            </div>
        </header>

        {{-- Main Content Area: Interactive Shell Hub --}}
        <main class="flex-1 bg-[#F7F8FA] p-4 flex flex-col gap-4 overflow-y-auto">
            {{-- Welcome Banner --}}
            <div class="bg-gradient-to-r from-primary-container to-primary-container rounded-2xl p-4 text-white shadow-md">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-[11px] font-bold uppercase tracking-wider bg-surface-container-lowest/20 px-2 py-0.5 rounded-full">Cổng Học Sinh & Phụ Huynh</span>
                        <h2 class="text-lg font-bold mt-1">Xin chào, {{ $student?->name ?? 'Học viên' }}</h2>
                        <p class="text-xs text-white/90 mt-0.5">Lớp: {{ $student?->currentClass?->name ?? 'Chưa xếp lớp' }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-surface-container-lowest/20 backdrop-blur-xs flex items-center justify-center text-white">
                        <span class="material-symbols-outlined text-2xl">school</span>
                    </div>
                </div>
            </div>

            {{-- Features Quick Access Grid --}}
            <div>
                <h3 class="text-xs font-bold text-on-surface-variant uppercase tracking-wider mb-2.5 px-1">Các chức năng chính</h3>
                <div class="grid grid-cols-2 gap-3">
                    {{-- Trang chủ --}}
                    <a href="{{ route('portal.student.home', ['studentId' => $student?->id]) }}" class="bg-surface-container-lowest p-3.5 rounded-2xl border border-surface-container-highest hover:border-primary-container hover:shadow-md transition flex flex-col gap-2 group">
                        <div class="flex items-center justify-between">
                            <div class="w-10 h-10 rounded-xl bg-primary-container/10 text-primary flex items-center justify-center group-hover:scale-105 transition-transform">
                                <span class="material-symbols-outlined text-[22px]">home</span>
                            </div>
                            @if($student?->tuition)
                                <x-ui.badge color="success" :dot="false">
                                    {{ number_format($student->tuition->total_amount) }}đ
                                </x-ui.badge>
                            @endif
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-on-surface group-hover:text-primary transition">Trang chủ</h4>
                            <p class="text-[11px] text-on-surface-variant mt-0.5 line-clamp-1">Thông tin học sinh & học phí</p>
                        </div>
                        <span class="text-[10px] font-bold text-primary flex items-center gap-0.5 mt-auto">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </a>

                    {{-- Học tập & Nộp bài --}}
                    <a href="{{ route('portal.student.homework', ['studentId' => $student?->id]) }}" class="bg-surface-container-lowest p-3.5 rounded-2xl border border-surface-container-highest hover:border-primary-container hover:shadow-md transition flex flex-col gap-2 group">
                        <div class="flex items-center justify-between">
                            <div class="w-10 h-10 rounded-xl bg-secondary/10 text-secondary flex items-center justify-center group-hover:scale-105 transition-transform">
                                <span class="material-symbols-outlined text-[22px]">upload_file</span>
                            </div>
                            <x-ui.badge :color="$submittedHomeworksCount == 6 ? 'success' : 'secondary'" :dot="false">
                                {{ $submittedHomeworksCount }}/6 nộp
                            </x-ui.badge>
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-on-surface group-hover:text-secondary transition">Học tập & Nộp bài</h4>
                            <p class="text-[11px] text-on-surface-variant mt-0.5 line-clamp-1">Video, từ vựng, workbook</p>
                        </div>
                        <span class="text-[10px] font-bold text-secondary flex items-center gap-0.5 mt-auto">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </a>

                    {{-- Luyện phát âm AI --}}
                    <a href="{{ route('portal.student.pronunciation', ['studentId' => $student?->id]) }}" class="bg-surface-container-lowest p-3.5 rounded-2xl border border-surface-container-highest hover:border-primary-container hover:shadow-md transition flex flex-col gap-2 group">
                        <div class="flex items-center justify-between">
                            <div class="w-10 h-10 rounded-xl bg-error/10 text-error flex items-center justify-center group-hover:scale-105 transition-transform">
                                <span class="material-symbols-outlined text-[22px]">mic</span>
                            </div>
                            @if($pronunciationCount > 0)
                                <x-ui.badge color="error" :dot="false">
                                    {{ $pronunciationCount }} bài
                                </x-ui.badge>
                            @endif
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-on-surface group-hover:text-error transition">Luyện phát âm AI</h4>
                            <p class="text-[11px] text-on-surface-variant mt-0.5 line-clamp-1">Thu âm & chấm giọng đọc</p>
                        </div>
                        <span class="text-[10px] font-bold text-error flex items-center gap-0.5 mt-auto">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </a>

                    {{-- Danh sách thông báo --}}
                    <a href="{{ route('portal.student.notifications', ['studentId' => $student?->id]) }}" class="bg-surface-container-lowest p-3.5 rounded-2xl border border-surface-container-highest hover:border-primary-container hover:shadow-md transition flex flex-col gap-2 group">
                        <div class="flex items-center justify-between">
                            <div class="w-10 h-10 rounded-xl bg-warning-container text-warning flex items-center justify-center group-hover:scale-105 transition-transform relative">
                                <span class="material-symbols-outlined text-[22px]">notifications</span>
                                @if($unreadNotifsCount > 0)
                                    <span class="absolute top-0 right-0 w-2.5 h-2.5 bg-error rounded-full border border-white"></span>
                                @endif
                            </div>
                            @if($unreadNotifsCount > 0)
                                <x-ui.badge color="error" :dot="false">
                                    {{ $unreadNotifsCount }} mới
                                </x-ui.badge>
                            @else
                                <x-ui.badge color="neutral" :dot="false">
                                    Đã đọc
                                </x-ui.badge>
                            @endif
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-on-surface group-hover:text-warning transition">Hộp thư Thông báo</h4>
                            <p class="text-[11px] text-on-surface-variant mt-0.5 line-clamp-1">Học phí, sinh nhật, nghỉ lễ</p>
                        </div>
                        <span class="text-[10px] font-bold text-warning flex items-center gap-0.5 mt-auto">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </a>

                    {{-- Khảo sát chất lượng --}}
                    <a href="{{ route('portal.student.survey', ['studentId' => $student?->id]) }}" class="bg-surface-container-lowest p-3.5 rounded-2xl border border-surface-container-highest hover:border-primary-container hover:shadow-md transition flex flex-col gap-2 group">
                        <div class="flex items-center justify-between">
                            <div class="w-10 h-10 rounded-xl bg-tertiary/10 text-tertiary flex items-center justify-center group-hover:scale-105 transition-transform">
                                <span class="material-symbols-outlined text-[22px]">assignment</span>
                            </div>
                            @if($surveyCount > 0)
                                <x-ui.badge color="success" :dot="false">
                                    {{ $surveyCount }} đã làm
                                </x-ui.badge>
                            @endif
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-on-surface group-hover:text-tertiary transition">Khảo sát Đánh giá</h4>
                            <p class="text-[11px] text-on-surface-variant mt-0.5 line-clamp-1">Cơ sở vật chất & giáo trình</p>
                        </div>
                        <span class="text-[10px] font-bold text-tertiary flex items-center gap-0.5 mt-auto">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </a>

                    {{-- Phụ huynh gửi feedback chặng học --}}
                    <a href="{{ route('portal.student.feedback', ['studentId' => $student?->id]) }}" class="bg-surface-container-lowest p-3.5 rounded-2xl border border-surface-container-highest hover:border-primary-container hover:shadow-md transition flex flex-col gap-2 group">
                        <div class="flex items-center justify-between">
                            <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center group-hover:scale-105 transition-transform">
                                <span class="material-symbols-outlined text-[22px]">rate_review</span>
                            </div>
                            @if($hasFeedback)
                                <span class="text-[10px] font-bold text-purple-700 bg-purple-50 border border-purple-200 px-1.5 py-0.5 rounded-md">
                                    Đã phản hồi
                                </span>
                            @else
                                <x-ui.badge color="neutral" :dot="false">
                                    Chưa gửi
                                </x-ui.badge>
                            @endif
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-on-surface group-hover:text-purple-600 transition">Gửi Feedback chặng</h4>
                            <p class="text-[11px] text-on-surface-variant mt-0.5 line-clamp-1">Đánh giá chặng học 5 sao</p>
                        </div>
                        <span class="text-[10px] font-bold text-purple-600 flex items-center gap-0.5 mt-auto">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </a>
                </div>
            </div>

            {{-- Student Profile Summary --}}
            <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-4 shadow-2xs space-y-3">
                <div class="flex items-center justify-between border-b pb-2.5">
                    <h4 class="text-xs font-bold text-on-surface flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-[18px]">verified_user</span>
                        Thông tin tài khoản
                    </h4>
                    <x-ui.badge color="success" :pill="true">
                        {{ $student?->status_label ?? '—' }}
                    </x-ui.badge>
                </div>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div>
                        <span class="text-[10px] text-on-surface-variant/70 uppercase font-bold block">Mã học viên</span>
                        <span class="font-mono font-bold text-on-surface">{{ $student?->code ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="text-[10px] text-on-surface-variant/70 uppercase font-bold block">Số điện thoại</span>
                        <span class="font-mono text-on-surface">{{ $student?->phone ?? '—' }}</span>
                    </div>
                </div>
            </div>
        </main>

        {{-- Bottom Navigation Bar Component --}}
        @include('portal.partials.bottom-nav', ['activeTab' => 'home', 'student' => $student])
    </div>
</x-app-layout>
