<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('classes.academic-list') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">class</span>
                        Chi tiết lớp học Học thuật (Flow 1 — Bước #6)
                    </h1>
                    <p class="text-xs text-gray-500">Tiến độ chi tiết từng chặng học, unit bài giảng và timeline các bài kiểm tra định kỳ Big Test.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('classes.trial-booking') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold transition">
                    <span class="material-symbols-outlined text-[16px]">restart_alt</span>
                    <span>Bắt đầu lại (Bước #1)</span>
                </a>
                <a href="{{ route('classes.create') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container text-white text-xs font-semibold shadow-sm hover:bg-primary-dark transition">
                    <span class="material-symbols-outlined text-[16px]">add</span>
                    <span>Tạo thêm lớp mới</span>
                </a>
            </div>
        </div>
    </x-slot>

    @include('classes.partials.flow-header', ['activeStep' => 6])

    <div class="max-w-[1200px] mx-auto space-y-6">
        <!-- Class Header & Switcher (Exact Match BA) -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white p-5 rounded-2xl border border-gray-200 shadow-sm">
            <div>
                <div class="flex items-center gap-3">
                    <h2 class="text-xl font-bold text-gray-900 tracking-tight">
                        Chi tiết lớp {{ $class?->code ?? 'IELTS_INT_01' }}
                    </h2>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">
                        Đang học
                    </span>
                </div>
                <p class="text-xs text-gray-500 mt-0.5">Quản lý và theo dõi thông tin học thuật chi tiết của lớp học.</p>
            </div>

            <div class="flex items-center gap-2">
                <span class="text-xs text-gray-500 font-semibold">Chuyển lớp:</span>
                <select class="px-3 py-1.5 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold text-gray-900 focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container cursor-pointer"
                        onchange="window.location.href = '{{ route('classes.academic-detail') }}/' + this.value">
                    @foreach($classes as $c)
                        <option value="{{ $c->id }}" {{ ($class && $class->id === $c->id) ? 'selected' : '' }}>
                            {{ $c->name }} ({{ $c->code }})
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <!-- Grid Layout for Top Cards (Exact Match BA 2 Columns) -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- 1. Thông tin chung -->
            <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm hover:shadow-md transition-shadow">
                <div class="flex items-center gap-2 mb-5 border-b border-gray-100 pb-3">
                    <span class="material-symbols-outlined text-primary text-[22px]">info</span>
                    <h3 class="text-base font-bold text-gray-900">Thông tin chung</h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-4 gap-x-6 text-xs">
                    <div>
                        <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Lịch học</span>
                        <span class="font-bold text-gray-900">{{ $class?->schedule_text ?? 'Chưa xếp lịch' }}</span>
                    </div>
                    <div>
                        <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">CM quản lý</span>
                        <span class="font-bold text-gray-900">{{ $class?->assistant?->name ?? 'Chưa phân công' }}</span>
                    </div>
                    <div>
                        <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Giáo viên</span>
                        <span class="font-bold text-gray-900">{{ $class?->teacher?->name ?? 'Chưa phân công' }}</span>
                    </div>
                    <div>
                        <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Sĩ số</span>
                        <span class="font-bold text-primary font-mono">{{ $class?->students?->count() ?? 0 }}/{{ $class?->max_capacity ?? 15 }}</span>
                    </div>
                    <div>
                        <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Ngày khai giảng</span>
                        <span class="font-bold text-gray-900 font-mono">{{ $class?->start_date ? $class->start_date->format('d/m/Y') : 'Chưa xác định' }}</span>
                    </div>
                    <div>
                        <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Dự kiến kết thúc</span>
                        <span class="font-bold text-gray-900 font-mono">{{ $class?->end_date ? $class->end_date->format('d/m/Y') : 'Chưa xác định' }}</span>
                    </div>
                </div>
            </div>

            <!-- 2. Chương trình & Tiến độ -->
            <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm hover:shadow-md transition-shadow">
                <div class="flex items-center gap-2 mb-5 border-b border-gray-100 pb-3">
                    <span class="material-symbols-outlined text-primary text-[22px]">school</span>
                    <h3 class="text-base font-bold text-gray-900">Chương trình &amp; Tiến độ</h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-4 gap-x-6 text-xs">
                    <div>
                        <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Tên chương trình</span>
                        <span class="font-bold text-gray-900">{{ $class?->program ?? $class?->course?->name ?? 'IELTS Luyện thi' }}</span>
                    </div>
                    <div>
                        <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Chặng hiện tại</span>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-primary-container/10 text-primary font-bold text-[11px]">
                            Chặng 2 (Intermediate)
                        </span>
                    </div>
                    <div>
                        <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Ngày mở chặng</span>
                        <span class="font-bold text-gray-900 font-mono">15/10/2023</span>
                    </div>
                    <div>
                        <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider block mb-1">Unit/Buổi hiện tại</span>
                        <span class="font-bold text-gray-900 font-mono text-sm text-primary">Unit 5 - Buổi 12</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Lịch Big Test (Timeline Matching Exact BA) -->
        <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm">
            <div class="flex items-center gap-2 mb-6 border-b border-gray-100 pb-3">
                <span class="material-symbols-outlined text-primary text-[22px]">event_available</span>
                <h3 class="text-base font-bold text-gray-900">Lịch Big Test</h3>
            </div>

            <div class="relative pl-4 space-y-6 before:absolute before:left-[21px] before:top-3 before:bottom-3 before:w-0.5 before:bg-gray-200">
                <!-- Timeline Item 1 -->
                <div class="relative flex items-start gap-4 group">
                    <div class="w-7 h-7 rounded-full bg-emerald-100 flex items-center justify-center shrink-0 z-10 shadow-2xs">
                        <div class="w-3 h-3 rounded-full bg-emerald-600"></div>
                    </div>
                    <div class="flex-1 flex flex-col sm:flex-row sm:items-center justify-between p-3.5 bg-gray-50/70 hover:bg-gray-50 rounded-xl border border-gray-200/80 transition">
                        <div>
                            <span class="text-xs font-bold text-gray-900 block">Big Test 1 (Chặng Foundation)</span>
                            <span class="text-[11px] text-gray-500 font-mono">20/09/2023 • Trực tiếp tại phòng Lab A</span>
                        </div>
                        <div class="mt-2 sm:mt-0">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-emerald-100 text-emerald-800 font-bold text-[11px]">
                                <span class="material-symbols-outlined text-[14px] mr-1">check</span>
                                Đã duyệt gửi PH
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Timeline Item 2 -->
                <div class="relative flex items-start gap-4 group">
                    <div class="w-7 h-7 rounded-full bg-emerald-100 flex items-center justify-center shrink-0 z-10 shadow-2xs">
                        <div class="w-3 h-3 rounded-full bg-emerald-600"></div>
                    </div>
                    <div class="flex-1 flex flex-col sm:flex-row sm:items-center justify-between p-3.5 bg-gray-50/70 hover:bg-gray-50 rounded-xl border border-gray-200/80 transition">
                        <div>
                            <span class="text-xs font-bold text-gray-900 block">Big Test 2 (Giữa kỳ Intermediate)</span>
                            <span class="text-[11px] text-gray-500 font-mono">15/10/2023 • Trực tiếp tại phòng 301</span>
                        </div>
                        <div class="mt-2 sm:mt-0">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-emerald-100 text-emerald-800 font-bold text-[11px]">
                                <span class="material-symbols-outlined text-[14px] mr-1">check</span>
                                Đã duyệt gửi PH
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Timeline Item 3 -->
                <div class="relative flex items-start gap-4 group">
                    <div class="w-7 h-7 rounded-full bg-orange-100 flex items-center justify-center shrink-0 z-10 shadow-2xs">
                        <div class="w-3 h-3 rounded-full bg-primary-container"></div>
                    </div>
                    <div class="flex-1 flex flex-col sm:flex-row sm:items-center justify-between p-3.5 bg-orange-50/40 rounded-xl border border-orange-200 transition">
                        <div>
                            <span class="text-xs font-bold text-primary block">Big Test 3 (Cuối chặng 2)</span>
                            <span class="text-[11px] text-gray-500 font-mono">10/11/2023 • Dự kiến xếp lịch</span>
                        </div>
                        <div class="mt-2 sm:mt-0">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-orange-100 text-primary font-bold text-[11px]">
                                Sắp diễn ra
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Timeline Item 4 -->
                <div class="relative flex items-start gap-4 group">
                    <div class="w-7 h-7 rounded-full bg-gray-100 flex items-center justify-center shrink-0 z-10 shadow-2xs">
                        <div class="w-3 h-3 rounded-full bg-gray-400"></div>
                    </div>
                    <div class="flex-1 flex flex-col sm:flex-row sm:items-center justify-between p-3.5 bg-gray-50 rounded-xl border border-gray-200 transition">
                        <div>
                            <span class="text-xs font-bold text-gray-600 block">Big Test 4 (Final Test)</span>
                            <span class="text-[11px] text-gray-400 font-mono">05/12/2023 • Kết thúc khóa học</span>
                        </div>
                        <div class="mt-2 sm:mt-0">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-gray-100 text-gray-600 font-bold text-[11px]">
                                Chưa diễn ra
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
