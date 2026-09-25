<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                @can('system_category.manage')
                <a href="{{ route('academic-system.index', ['cat' => '04_Cong_Phu_Huynh_Hoc_Sinh']) }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                @endcan
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">smartphone</span>
                        Flow 4 — Bước 1: App Shell Phụ huynh / Học sinh (Khung Điều Hướng Mobile)
                    </h1>
                    <p class="text-xs text-gray-500">Giao diện khung điều hướng toàn diện cho Cổng Phụ huynh / Học sinh trên thiết bị di động.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('portal.student.home', ['studentId' => $student?->id]) }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container text-white text-xs font-semibold hover:bg-primary-dark transition shadow-sm">
                    <span class="material-symbols-outlined text-[18px]">cottage</span>
                    <span>Vào Trang chủ</span>
                </a>
            </div>
        </div>
    </x-slot>

    <!-- Outer Mobile Shell Container -->
    <div class="max-w-[430px] mx-auto bg-white min-h-[844px] shadow-2xl rounded-3xl border border-gray-200 overflow-hidden flex flex-col relative pb-20 my-4">
        <!-- Top App Bar (Exact Match Prototype) -->
        <header class="w-full top-0 sticky bg-background dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between px-4 h-16 z-40">
            <h1 class="font-bold text-2xl text-primary tracking-tight">MENGLISH</h1>
            <div class="flex items-center gap-2">
                @if($students && $students->count() > 1)
                    <select class="text-[11px] font-semibold py-1 px-2.5 bg-gray-100 hover:bg-gray-200 border-none rounded-full text-gray-700 cursor-pointer focus:ring-1 focus:ring-primary-container"
                            onchange="window.location.href = '{{ route('portal.app-shell') }}?student_id=' + this.value">
                        @foreach($students as $st)
                            <option value="{{ $st->id }}" {{ ($student && $student->id === $st->id) ? 'selected' : '' }}>
                                {{ $st->name }}
                            </option>
                        @endforeach
                    </select>
                @endif
                <a href="{{ route('portal.student.home', ['studentId' => $student?->id]) }}" class="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center overflow-hidden hover:opacity-80 transition-opacity active:scale-95 duration-100 text-primary">
                    <span class="material-symbols-outlined text-2xl">account_circle</span>
                </a>
            </div>
        </header>

        <!-- Main Content Area: Interactive Shell Hub -->
        <main class="flex-1 bg-[#F7F8FA] p-4 flex flex-col gap-4 overflow-y-auto">
            <!-- Welcome Banner -->
            <div class="bg-gradient-to-r from-primary-container to-orange-500 rounded-2xl p-4 text-white shadow-md">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-[11px] font-bold uppercase tracking-wider bg-white/20 px-2 py-0.5 rounded-full">Cổng Học Sinh & Phụ Huynh</span>
                        <h2 class="text-lg font-bold mt-1">Xin chào, {{ $student?->name ?? 'Học viên' }}</h2>
                        <p class="text-xs text-white/90 mt-0.5">Lớp: {{ $student?->currentClass?->name ?? 'Chưa xếp lớp' }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl bg-white/20 backdrop-blur-xs flex items-center justify-center text-white">
                        <span class="material-symbols-outlined text-2xl">school</span>
                    </div>
                </div>
            </div>

            <!-- Features Quick Access Grid -->
            <div>
                <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2.5 px-1">Các chức năng chính (Flow 4)</h3>
                <div class="grid grid-cols-2 gap-3">
                    <!-- Screen 2: Trang chủ -->
                    <a href="{{ route('portal.student.home', ['studentId' => $student?->id]) }}" class="bg-white p-3.5 rounded-2xl border border-gray-200 hover:border-primary-container hover:shadow-md transition flex flex-col gap-2 group">
                        <div class="flex items-center justify-between">
                            <div class="w-10 h-10 rounded-xl bg-orange-50 text-primary flex items-center justify-center group-hover:scale-105 transition-transform">
                                <span class="material-symbols-outlined text-[22px]">home</span>
                            </div>
                            @if($student?->tuition)
                                <span class="text-[10px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-1.5 py-0.5 rounded-md">
                                    {{ number_format($student->tuition->total_amount) }}đ
                                </span>
                            @endif
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-gray-900 group-hover:text-primary transition">Trang chủ</h4>
                            <p class="text-[11px] text-gray-500 mt-0.5 line-clamp-1">Thông tin học sinh & học phí</p>
                        </div>
                        <span class="text-[10px] font-bold text-primary flex items-center gap-0.5 mt-auto">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </a>

                    <!-- Screen 3: Học tập & Nộp bài -->
                    <a href="{{ route('portal.student.homework', ['studentId' => $student?->id]) }}" class="bg-white p-3.5 rounded-2xl border border-gray-200 hover:border-primary-container hover:shadow-md transition flex flex-col gap-2 group">
                        <div class="flex items-center justify-between">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 text-secondary flex items-center justify-center group-hover:scale-105 transition-transform">
                                <span class="material-symbols-outlined text-[22px]">upload_file</span>
                            </div>
                            <span class="text-[10px] font-bold {{ $submittedHomeworksCount == 6 ? 'text-emerald-700 bg-emerald-50 border border-emerald-200' : 'text-blue-700 bg-blue-50 border border-blue-200' }} px-1.5 py-0.5 rounded-md">
                                {{ $submittedHomeworksCount }}/6 nộp
                            </span>
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-gray-900 group-hover:text-secondary transition">Học tập & Nộp bài</h4>
                            <p class="text-[11px] text-gray-500 mt-0.5 line-clamp-1">Video, từ vựng, workbook</p>
                        </div>
                        <span class="text-[10px] font-bold text-secondary flex items-center gap-0.5 mt-auto">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </a>

                    <!-- Screen 4: Luyện phát âm AI -->
                    <a href="{{ route('portal.student.pronunciation', ['studentId' => $student?->id]) }}" class="bg-white p-3.5 rounded-2xl border border-gray-200 hover:border-primary-container hover:shadow-md transition flex flex-col gap-2 group">
                        <div class="flex items-center justify-between">
                            <div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center group-hover:scale-105 transition-transform">
                                <span class="material-symbols-outlined text-[22px]">mic</span>
                            </div>
                            @if($pronunciationCount > 0)
                                <span class="text-[10px] font-bold text-rose-700 bg-rose-50 border border-rose-200 px-1.5 py-0.5 rounded-md">
                                    {{ $pronunciationCount }} bài
                                </span>
                            @endif
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-gray-900 group-hover:text-rose-600 transition">Luyện phát âm AI</h4>
                            <p class="text-[11px] text-gray-500 mt-0.5 line-clamp-1">Thu âm & chấm giọng đọc</p>
                        </div>
                        <span class="text-[10px] font-bold text-rose-600 flex items-center gap-0.5 mt-auto">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </a>

                    <!-- Screen 5: Danh sách thông báo -->
                    <a href="{{ route('portal.student.notifications', ['studentId' => $student?->id]) }}" class="bg-white p-3.5 rounded-2xl border border-gray-200 hover:border-primary-container hover:shadow-md transition flex flex-col gap-2 group">
                        <div class="flex items-center justify-between">
                            <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center group-hover:scale-105 transition-transform relative">
                                <span class="material-symbols-outlined text-[22px]">notifications</span>
                                @if($unreadNotifsCount > 0)
                                    <span class="absolute top-0 right-0 w-2.5 h-2.5 bg-red-500 rounded-full border border-white"></span>
                                @endif
                            </div>
                            @if($unreadNotifsCount > 0)
                                <span class="text-[10px] font-bold text-red-700 bg-red-50 border border-red-200 px-1.5 py-0.5 rounded-md">
                                    {{ $unreadNotifsCount }} mới
                                </span>
                            @else
                                <span class="text-[10px] font-medium text-gray-500 bg-gray-100 px-1.5 py-0.5 rounded-md">
                                    Đã đọc
                                </span>
                            @endif
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-gray-900 group-hover:text-amber-600 transition">Hộp thư Thông báo</h4>
                            <p class="text-[11px] text-gray-500 mt-0.5 line-clamp-1">Học phí, sinh nhật, nghỉ lễ</p>
                        </div>
                        <span class="text-[10px] font-bold text-amber-600 flex items-center gap-0.5 mt-auto">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </a>

                    <!-- Screen 6: Khảo sát chất lượng -->
                    <a href="{{ route('portal.student.survey', ['studentId' => $student?->id]) }}" class="bg-white p-3.5 rounded-2xl border border-gray-200 hover:border-primary-container hover:shadow-md transition flex flex-col gap-2 group">
                        <div class="flex items-center justify-between">
                            <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center group-hover:scale-105 transition-transform">
                                <span class="material-symbols-outlined text-[22px]">assignment</span>
                            </div>
                            @if($surveyCount > 0)
                                <span class="text-[10px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-1.5 py-0.5 rounded-md">
                                    {{ $surveyCount }} đã làm
                                </span>
                            @endif
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-gray-900 group-hover:text-emerald-600 transition">Khảo sát Đánh giá</h4>
                            <p class="text-[11px] text-gray-500 mt-0.5 line-clamp-1">Cơ sở vật chất & giáo trình</p>
                        </div>
                        <span class="text-[10px] font-bold text-emerald-600 flex items-center gap-0.5 mt-auto">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </a>

                    <!-- Screen 7: Phụ huynh gửi feedback chặng học -->
                    <a href="{{ route('portal.student.feedback', ['studentId' => $student?->id]) }}" class="bg-white p-3.5 rounded-2xl border border-gray-200 hover:border-primary-container hover:shadow-md transition flex flex-col gap-2 group">
                        <div class="flex items-center justify-between">
                            <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center group-hover:scale-105 transition-transform">
                                <span class="material-symbols-outlined text-[22px]">rate_review</span>
                            </div>
                            @if($hasFeedback)
                                <span class="text-[10px] font-bold text-purple-700 bg-purple-50 border border-purple-200 px-1.5 py-0.5 rounded-md">
                                    Đã phản hồi
                                </span>
                            @else
                                <span class="text-[10px] font-medium text-gray-500 bg-gray-100 px-1.5 py-0.5 rounded-md">
                                    Chưa gửi
                                </span>
                            @endif
                        </div>
                        <div>
                            <h4 class="text-xs font-bold text-gray-900 group-hover:text-purple-600 transition">Gửi Feedback chặng</h4>
                            <p class="text-[11px] text-gray-500 mt-0.5 line-clamp-1">Đánh giá chặng học 5 sao</p>
                        </div>
                        <span class="text-[10px] font-bold text-purple-600 flex items-center gap-0.5 mt-auto">Mở xem <span class="material-symbols-outlined text-[12px]">chevron_right</span></span>
                    </a>
                </div>
            </div>

            <!-- Student Profile Summary -->
            <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-2xs space-y-3">
                <div class="flex items-center justify-between border-b pb-2.5">
                    <h4 class="text-xs font-bold text-gray-800 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-[18px]">verified_user</span>
                        Thông tin tài khoản
                    </h4>
                    <span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 text-[10px] font-bold rounded-full border border-emerald-200">
                        {{ $student?->status_label ?? '—' }}
                    </span>
                </div>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div>
                        <span class="text-[10px] text-gray-400 uppercase font-bold block">Mã học viên</span>
                        <span class="font-mono font-bold text-gray-800">{{ $student?->code ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="text-[10px] text-gray-400 uppercase font-bold block">Số điện thoại</span>
                        <span class="font-mono text-gray-800">{{ $student?->phone ?? '—' }}</span>
                    </div>
                </div>
            </div>
        </main>

        <!-- Bottom Navigation Bar Component -->
        @include('portal.partials.bottom-nav', ['activeTab' => 'home', 'student' => $student])
    </div>
</x-app-layout>
