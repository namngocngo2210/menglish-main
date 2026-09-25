<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('classes.create') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">event_available</span>
                        Đặt lịch khách học thử vào buổi (Flow 1 — Bước #1)
                    </h1>
                    <p class="text-xs text-gray-500">Khách hàng được tiếp nhận từ CRM hoặc Test đầu vào để xếp vào buổi học thử trải nghiệm tại cơ sở.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('classes.create') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container text-white text-xs font-semibold shadow-sm hover:bg-primary-dark transition">
                    <span class="material-symbols-outlined text-[18px]">group_add</span>
                    <span>Tạo lớp mới (Bước #2)</span>
                </a>
            </div>
        </div>
    </x-slot>

    @include('classes.partials.flow-header', ['activeStep' => 1])

    

    <!-- Main Container Matching Prototype 01_Web_Admin/12_dat_lich_hoc_thu_popup -->
    <div class="max-w-4xl mx-auto space-y-6">
        <!-- Interactive Card Modal Container -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-md overflow-hidden" x-data="{
            step: 1,
            selectedClass: '{{ $classes->first()?->code ?? 'IELTS-PRE-01' }}',
            selectedClassId: '{{ $classes->first()?->id ?? 1 }}',
            selectedClassName: '{{ $classes->first()?->name ?? 'IELTS-PRE-01' }}',
            selectedSchedule: '{{ addslashes($classes->first()?->schedule_text ?? '') }}',
            selectedSession: '',
            searchQuery: '',
            classSchedules: {{ json_encode($classes->pluck('schedule_text', 'id')) }},
            selectClass(id, code, name) {
                this.selectedClassId = id;
                this.selectedClass = code;
                this.selectedClassName = name;
                this.selectedSchedule = this.classSchedules[id] || '';
                this.selectedSession = '';
            },
            selectSession(sessionText) {
                this.selectedSession = sessionText;
            },
            pickSession(classId, className, sessionText) {
                this.selectClass(classId, className, className);
                this.selectedSession = sessionText;
            }
        }">
            <!-- Header (Exact Match BA) -->
            <div class="flex justify-between items-center p-6 border-b border-gray-100 bg-white">
                <div>
                    <h2 class="text-xl md:text-2xl font-bold text-gray-900 tracking-tight">Đặt lịch khách học thử vào buổi</h2>
                    <p class="text-xs md:text-sm text-gray-500 mt-1">
                        Khách hàng: <strong class="text-gray-800">{{ $customerName }}</strong> — Trình độ test: <span class="px-2 py-0.5 rounded bg-orange-100 text-primary font-bold text-xs">{{ $customerLevel }}</span>
                    </p>
                </div>
                <div class="p-2 rounded-lg bg-gray-50 text-gray-400">
                    <span class="material-symbols-outlined text-[20px]">calendar_add_on</span>
                </div>
            </div>

            <!-- Steps Indicator (Exact Match BA) -->
            <div class="bg-gray-50 px-6 py-3 border-b border-gray-100 flex items-center gap-4">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold transition-colors"
                         :class="step === 1 ? 'bg-primary-container text-white' : 'bg-emerald-600 text-white'">
                        <template x-if="step === 1"><span>1</span></template>
                        <template x-if="step === 2"><span class="material-symbols-outlined text-[14px]">check</span></template>
                    </div>
                    <span class="text-xs font-bold text-gray-900">Chọn lớp</span>
                </div>
                <div class="h-px bg-gray-300 w-8"></div>
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold transition-colors"
                         :class="step === 2 ? 'bg-primary-container text-white' : 'bg-gray-200 text-gray-500'">
                        2
                    </div>
                    <span class="text-xs font-semibold" :class="step === 2 ? 'text-gray-900' : 'text-gray-500'">Chọn buổi học</span>
                </div>
            </div>

            <!-- Form Wrapper -->
            <form action="{{ route('classes.trial-booking.store') }}" method="POST">
                @csrf
                <input type="hidden" name="customer_name" value="{{ $customerName }}">
                <input type="hidden" name="customer_level" value="{{ $customerLevel }}">
                <input type="hidden" name="branch_name" value="{{ $customerBranch }}">
                <input type="hidden" name="class_id" :value="selectedClassId">
                <input type="hidden" name="class_name" :value="selectedClassName">
                <input type="hidden" name="session_time" :value="selectedSession">

                <!-- Content Area -->
                <div class="p-6 bg-white min-h-[380px]">
                    <!-- STEP 1: Chọn Lớp -->
                    <div x-show="step === 1" x-transition.opacity>
                        <!-- Filters & Search -->
                        <div class="flex flex-col sm:flex-row gap-4 mb-4">
                            <div class="flex-1">
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Tìm kiếm lớp</label>
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-[18px]">search</span>
                                    <input x-model="searchQuery"
                                           class="w-full pl-9 pr-3 py-2 border border-gray-200 rounded-lg bg-white text-xs text-gray-900 focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition"
                                           placeholder="Nhập tên hoặc mã lớp..." type="text"/>
                                </div>
                            </div>
                            <div class="w-full sm:w-60">
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Chi nhánh khách</label>
                                <select class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 text-xs text-gray-700 focus:outline-none cursor-default" disabled>
                                    <option selected>{{ $customerBranch }}</option>
                                </select>
                            </div>
                        </div>

                        <!-- Class List Table -->
                        <div class="border border-gray-200 rounded-xl overflow-hidden shadow-2xs">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-gray-50 border-b border-gray-200 text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                                        <th class="py-2.5 px-4 w-10"></th>
                                        <th class="py-2.5 px-4">Tên lớp</th>
                                        <th class="py-2.5 px-4">Trình độ</th>
                                        <th class="py-2.5 px-4">Chi nhánh</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 text-xs">
                                    @forelse($classes as $c)
                                        <tr class="hover:bg-orange-50/40 transition-colors cursor-pointer"
                                            :class="selectedClassId == {{ $c->id }} ? 'bg-orange-50/60 font-semibold' : ''"
                                            @click="selectClass({{ $c->id }}, '{{ $c->code }}', '{{ $c->name }}')">
                                            <td class="py-3 px-4">
                                                <input type="radio" name="class_selection" value="{{ $c->id }}"
                                                       :checked="selectedClassId == {{ $c->id }}"
                                                       class="w-4 h-4 text-primary focus:ring-primary-container border-gray-300">
                                            </td>
                                            <td class="py-3 px-4">
                                                <div class="font-bold text-gray-900">{{ $c->name }}</div>
                                                <div class="text-[11px] text-gray-500 font-normal">
                                                    Mã: <span class="font-mono">{{ $c->code }}</span> • Khai giảng: {{ $c->start_date ? $c->start_date->format('d/m/Y') : '12/10/2023' }}
                                                </div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="font-medium text-gray-800">{{ $c->level ?? $c->course?->name ?? 'Pre-IELTS' }}</span>
                                                    @if($loop->first)
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 font-bold text-[10px] uppercase">
                                                            Gợi ý phù hợp
                                                        </span>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="py-3 px-4 text-gray-600 font-medium">
                                                {{ $c->branch?->name ?? 'Cầu Giấy' }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="p-6 text-center text-gray-400 text-xs">
                                                Chưa có dữ liệu lớp học phù hợp. Vui lòng tạo lớp mới ở Bước #2.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- STEP 2: Chọn Buổi Học -->
                    <div x-show="step === 2" x-transition.opacity>
                        <div class="mb-4">
                            <h3 class="text-base font-bold text-gray-900">Chọn buổi học thử</h3>
                            <p class="text-xs text-gray-500">
                                Lớp đã chọn: <strong class="text-primary font-bold" x-text="selectedClassName"></strong>
                            </p>
                            <p class="text-xs text-gray-500 mt-1" x-show="selectedSchedule">
                                Lịch học: <strong class="text-gray-800 font-mono" x-text="selectedSchedule"></strong>
                            </p>
                        </div>

                        <!-- Sessions Grid: buổi học thật từ ClassSession (sắp diễn ra, theo chi nhánh) -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            @forelse($upcomingSessions as $upcoming)
                                @php
                                    $sessionLabel = $upcoming->date->translatedFormat('l, d/m/Y').' - '.$upcoming->start_time->format('H:i').' - '.$upcoming->end_time->format('H:i');
                                    $isSelected = "selectedSession === @js($sessionLabel)";
                                @endphp
                                <label class="block cursor-pointer relative" @click="pickSession({{ $upcoming->class_id }}, @js($upcoming->classModel?->name), @js($sessionLabel))">
                                    <input type="radio" name="session_selection_radio" value="{{ $sessionLabel }}" class="peer sr-only">
                                    <div class="p-4 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 transition-all peer-checked:border-primary-container peer-checked:ring-2 peer-checked:ring-primary-container/20 peer-checked:bg-orange-50/40 h-full flex flex-col justify-between"
                                         :class="{{ $isSelected }} ? 'border-primary-container ring-2 ring-primary-container/20 bg-orange-50/40' : ''">
                                        <div class="flex items-start justify-between mb-3">
                                            <div class="flex items-center gap-1.5 text-gray-800 font-bold text-xs">
                                                <span class="material-symbols-outlined text-[18px] text-primary">calendar_today</span>
                                                <span>{{ $upcoming->date->translatedFormat('l, d/m/Y') }}</span>
                                            </div>
                                            <div class="w-4 h-4 rounded-full border border-gray-300 flex items-center justify-center"
                                                 :class="{{ $isSelected }} ? 'border-primary-container bg-primary-container text-white' : ''">
                                                <span class="material-symbols-outlined text-[12px]" x-show="{{ $isSelected }}">check</span>
                                            </div>
                                        </div>
                                        <div class="text-[11px] text-gray-600 font-semibold mb-2">
                                            {{ $upcoming->classModel?->name ?? 'Lớp đã bị xóa' }}{{ $upcoming->shift_name ? ' · '.$upcoming->shift_name : '' }}
                                        </div>
                                        <div class="flex items-center gap-1.5 text-gray-500 text-xs">
                                            <span class="material-symbols-outlined text-[16px]">schedule</span>
                                            <span class="font-mono">{{ $upcoming->start_time->format('H:i') }} - {{ $upcoming->end_time->format('H:i') }}</span>
                                        </div>
                                    </div>
                                </label>
                            @empty
                                <div class="md:col-span-3 p-6 rounded-xl border border-dashed border-gray-300 bg-gray-50 text-center text-xs text-gray-500">
                                    Chưa có buổi học nào đã lên lịch sắp diễn ra tại chi nhánh này.
                                    Hãy tạo lớp và render thời khóa biểu trước khi đặt lịch học thử.
                                </div>
                            @endforelse
                        </div>

                        <!-- Confirmation Summary Box -->
                        <div x-show="selectedSession" class="mt-6 p-4 bg-orange-50/60 rounded-xl border border-orange-200/80">
                            <h4 class="text-xs font-bold text-gray-900 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-primary text-[18px]">verified</span>
                                Xác nhận thông tin học thử
                            </h4>
                            <ul class="space-y-1 text-xs text-gray-700">
                                <li><span class="text-gray-500 inline-block w-28">Khách hàng:</span> <strong class="text-gray-900">{{ $customerName }}</strong></li>
                                <li><span class="text-gray-500 inline-block w-28">Lớp đăng ký:</span> <strong class="text-gray-900" x-text="selectedClassName"></strong></li>
                                <li><span class="text-gray-500 inline-block w-28">Thời gian buổi:</span> <strong class="text-primary font-bold" x-text="selectedSession"></strong></li>
                                <li><span class="text-gray-500 inline-block w-28">Chi nhánh:</span> <strong class="text-gray-900">{{ $customerBranch }}</strong></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Footer Actions (Exact Match BA) -->
                <div class="p-4 md:p-6 border-t border-gray-100 bg-gray-50 flex justify-end gap-3">
                    <!-- Step 1 Buttons -->
                    <template x-if="step === 1">
                        <div class="flex gap-2">
                            <a href="{{ route('classes.create') }}" class="px-4 py-2 rounded-xl bg-white border border-gray-300 text-xs font-semibold text-gray-700 hover:bg-gray-100 transition">
                                Bỏ qua
                            </a>
                            <button type="button" @click="step = 2"
                                    :disabled="!selectedClassId"
                                    class="px-5 py-2 rounded-xl bg-primary-container text-white text-xs font-bold shadow-sm hover:bg-primary-dark transition disabled:opacity-50 disabled:cursor-not-allowed">
                                Tiếp theo →
                            </button>
                        </div>
                    </template>

                    <!-- Step 2 Buttons -->
                    <template x-if="step === 2">
                        <div class="flex gap-2">
                            <button type="button" @click="step = 1" class="px-4 py-2 rounded-xl bg-white border border-gray-300 text-xs font-semibold text-gray-700 hover:bg-gray-100 transition">
                                ← Quay lại
                            </button>
                            <button type="submit"
                                    :disabled="!selectedSession"
                                    class="px-5 py-2 rounded-xl bg-primary-container text-white text-xs font-bold shadow-sm hover:bg-primary-dark transition disabled:opacity-50 disabled:cursor-not-allowed">
                                Xác nhận đặt lịch học thử
                            </button>
                        </div>
                    </template>
                </div>
            </form>
        </div>

        <!-- Recent Trial Bookings (Database Backed) -->
        @if($bookings->isNotEmpty())
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[18px]">history</span>
                        Lịch sử khách học thử đã xác nhận gần đây
                    </h3>
                    <span class="text-xs text-gray-500 font-mono">{{ $bookings->count() }} lượt</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase">
                                <th class="py-2 px-3">Mã phiếu</th>
                                <th class="py-2 px-3">Khách hàng</th>
                                <th class="py-2 px-3">Lớp học</th>
                                <th class="py-2 px-3">Buổi học</th>
                                <th class="py-2 px-3">Thời gian đặt</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($bookings as $b)
                                <tr>
                                    <td class="py-2.5 px-3 font-mono font-bold text-gray-700">{{ $b->record_code }}</td>
                                    <td class="py-2.5 px-3 font-bold text-gray-900">{{ $b->data['customer_name'] ?? '—' }}</td>
                                    <td class="py-2.5 px-3 text-gray-800">{{ $b->data['class_name'] ?? '—' }}</td>
                                    <td class="py-2.5 px-3 text-primary font-medium">{{ $b->data['session_time'] ?? '—' }}</td>
                                    <td class="py-2.5 px-3 text-gray-500">{{ $b->created_at->format('d/m/Y H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
