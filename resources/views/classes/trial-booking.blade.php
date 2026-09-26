<x-app-layout>
    <x-ui.page-header title="Đặt lịch khách học thử vào buổi" icon="event_available">
        <x-slot:actions>
            <x-ui.button icon="group_add" :href="route('classes.create')">Tạo lớp mới</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>


    

    {{-- Main Container Matching Prototype 01_Web_Admin/12_dat_lich_hoc_thu_popup --}}
    <div class="max-w-4xl mx-auto space-y-6">
        {{-- Interactive Card Modal Container --}}
        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-md overflow-hidden" x-data="{
            step: 1,
            selectedClass: '{{ $classes->first()?->code ?? '' }}',
            selectedClassId: '{{ $classes->first()?->id ?? 1 }}',
            selectedClassName: '{{ $classes->first()?->name ?? '' }}',
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
            {{-- Header --}}
            <div class="flex justify-between items-center p-6 border-b border-surface-container-highest bg-surface-container-lowest">
                <div>
                    <h2 class="text-xl md:text-2xl font-bold text-on-surface tracking-tight">Đặt lịch khách học thử vào buổi</h2>
                    <p class="text-xs md:text-sm text-on-surface-variant mt-1">Chọn lớp và buổi học thử phù hợp cho khách.</p>
                </div>
                <div class="p-2 rounded-lg bg-surface-container-low text-on-surface-variant/70">
                    <span class="material-symbols-outlined text-[20px]">calendar_add_on</span>
                </div>
            </div>

            {{-- Steps Indicator --}}
            <div class="bg-surface-container-low px-6 py-3 border-b border-surface-container-highest flex items-center gap-4">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold transition-colors"
                         :class="step === 1 ? 'bg-primary-container text-white' : 'bg-tertiary text-white'">
                        <template x-if="step === 1"><span>1</span></template>
                        <template x-if="step === 2"><span class="material-symbols-outlined text-[14px]">check</span></template>
                    </div>
                    <span class="text-xs font-bold text-on-surface">Chọn lớp</span>
                </div>
                <div class="h-px bg-surface-container-highest w-8"></div>
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold transition-colors"
                         :class="step === 2 ? 'bg-primary-container text-white' : 'bg-surface-container-high text-on-surface-variant'">
                        2
                    </div>
                    <span class="text-xs font-semibold" :class="step === 2 ? 'text-on-surface' : 'text-on-surface-variant'">Chọn buổi học</span>
                </div>
            </div>

            {{-- Form Wrapper --}}
            <form action="{{ route('classes.trial-booking.store') }}" method="POST">
                @csrf
                <input type="hidden" name="branch_name" value="{{ $customerBranch }}">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 px-6 pt-5 bg-surface-container-lowest">
                    <x-ui.input id="trial_customer_name" name="customer_name" label="Tên khách hàng" required :value="$customerName" placeholder="Họ tên khách học thử" class="text-xs" />
                    <x-ui.input id="trial_customer_level" name="customer_level" label="Trình độ test" :value="$customerLevel" placeholder="VD: Pre-IELTS" class="text-xs" />
                </div>
                <input type="hidden" name="class_id" :value="selectedClassId">
                <input type="hidden" name="class_name" :value="selectedClassName">
                <input type="hidden" name="session_time" :value="selectedSession">

                {{-- Content Area --}}
                <div class="p-6 bg-surface-container-lowest min-h-[380px]">
                    {{-- STEP 1: Chọn Lớp --}}
                    <div x-show="step === 1" x-transition.opacity>
                        {{-- Filters & Search --}}
                        <div class="flex flex-col sm:flex-row gap-4 mb-4">
                            <div class="flex-1">
                                <x-ui.input label="Tìm kiếm lớp" icon="search" x-model="searchQuery" placeholder="Nhập tên hoặc mã lớp..." class="text-xs" />
                            </div>
                            <div class="w-full sm:w-60">
                                <x-ui.select label="Chi nhánh khách" class="text-xs" :value="$selectedBranchId" :options="$branches->pluck('name', 'id')"
                                             onchange="const u = new URL(window.location.href); u.searchParams.set('branch_id', this.value); window.location = u.toString();" />
                            </div>
                        </div>

                        {{-- Class List Table --}}
                        <x-ui.data-table>
                            <table>
                                <thead>
                                    <tr>
                                        <th class="w-10"></th>
                                        <th>Tên lớp</th>
                                        <th>Trình độ</th>
                                        <th>Chi nhánh</th>
                                    </tr>
                                </thead>
                                <tbody class="text-xs">
                                    @forelse($classes as $c)
                                        <tr class="cursor-pointer"
                                            :class="selectedClassId == {{ $c->id }} ? 'bg-primary-container/10 font-semibold' : ''"
                                            @click="selectClass({{ $c->id }}, '{{ $c->code }}', '{{ $c->name }}')">
                                            <td>
                                                <input type="radio" name="class_selection" value="{{ $c->id }}"
                                                       :checked="selectedClassId == {{ $c->id }}"
                                                       class="w-4 h-4 text-primary focus:ring-primary-container border-outline-variant">
                                            </td>
                                            <td>
                                                <div class="font-bold text-on-surface">{{ $c->name }}</div>
                                                <div class="text-[11px] text-on-surface-variant font-normal">
                                                    Mã: <span class="font-mono">{{ $c->code }}</span> • Khai giảng: {{ $c->start_date ? $c->start_date->format('d/m/Y') : '12/10/2023' }}
                                                </div>
                                            </td>
                                            <td>
                                                <div class="flex items-center gap-1.5">
                                                    <span class="font-medium text-on-surface">{{ $c->level ?? $c->course?->name ?? 'Chưa cập nhật' }}</span>
                                                    @if($loop->first)
                                                        <x-ui.badge color="success" class="uppercase">Gợi ý phù hợp</x-ui.badge>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="text-on-surface-variant font-medium">
                                                {{ $c->branch?->name ?? 'Chưa cập nhật' }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4"><x-ui.empty-state icon="school" title="Chưa có dữ liệu lớp học phù hợp. Vui lòng tạo lớp mới." /></td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </x-ui.data-table>
                    </div>

                    {{-- STEP 2: Chọn Buổi Học --}}
                    <div x-show="step === 2" x-transition.opacity>
                        <div class="mb-4">
                            <h3 class="text-base font-bold text-on-surface">Chọn buổi học thử</h3>
                            <p class="text-xs text-on-surface-variant">
                                Lớp đã chọn: <strong class="text-primary font-bold" x-text="selectedClassName"></strong>
                            </p>
                            <p class="text-xs text-on-surface-variant mt-1" x-show="selectedSchedule">
                                Lịch học: <strong class="text-on-surface font-mono" x-text="selectedSchedule"></strong>
                            </p>
                        </div>

                        {{-- Sessions Grid: buổi học thật từ ClassSession (sắp diễn ra, theo chi nhánh) --}}
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            @forelse($upcomingSessions as $upcoming)
                                @php
                                    $sessionLabel = $upcoming->date->translatedFormat('l, d/m/Y').' - '.$upcoming->start_time->format('H:i').' - '.$upcoming->end_time->format('H:i');
                                    $isSelected = "selectedSession === @js($sessionLabel)";
                                @endphp
                                <label class="block cursor-pointer relative" @click="pickSession({{ $upcoming->class_id }}, @js($upcoming->classModel?->name), @js($sessionLabel))">
                                    <input type="radio" name="session_selection_radio" value="{{ $sessionLabel }}" class="peer sr-only">
                                    <div class="p-4 rounded-xl border border-surface-container-highest bg-surface-container-lowest hover:bg-surface-container-low transition-all peer-checked:border-primary-container peer-checked:ring-2 peer-checked:ring-primary-container/20 peer-checked:bg-primary-container/5 h-full flex flex-col justify-between"
                                         :class="{{ $isSelected }} ? 'border-primary-container ring-2 ring-primary-container/20 bg-primary-container/10' : ''">
                                        <div class="flex items-start justify-between mb-3">
                                            <div class="flex items-center gap-1.5 text-on-surface font-bold text-xs">
                                                <span class="material-symbols-outlined text-[18px] text-primary">calendar_today</span>
                                                <span>{{ $upcoming->date->translatedFormat('l, d/m/Y') }}</span>
                                            </div>
                                            <div class="w-4 h-4 rounded-full border border-outline-variant flex items-center justify-center"
                                                 :class="{{ $isSelected }} ? 'border-primary-container bg-primary-container text-white' : ''">
                                                <span class="material-symbols-outlined text-[12px]" x-show="{{ $isSelected }}">check</span>
                                            </div>
                                        </div>
                                        <div class="text-[11px] text-on-surface-variant font-semibold mb-2">
                                            {{ $upcoming->classModel?->name ?? 'Lớp đã bị xóa' }}{{ $upcoming->shift_name ? ' · '.$upcoming->shift_name : '' }}
                                        </div>
                                        <div class="flex items-center gap-1.5 text-on-surface-variant text-xs">
                                            <span class="material-symbols-outlined text-[16px]">schedule</span>
                                            <span class="font-mono">{{ $upcoming->start_time->format('H:i') }} - {{ $upcoming->end_time->format('H:i') }}</span>
                                        </div>
                                    </div>
                                </label>
                            @empty
                                <div class="md:col-span-3 p-6 rounded-xl border border-dashed border-outline-variant bg-surface-container-low text-center text-xs text-on-surface-variant">
                                    Chưa có buổi học nào đã lên lịch sắp diễn ra tại chi nhánh này.
                                    Hãy tạo lớp và render thời khóa biểu trước khi đặt lịch học thử.
                                </div>
                            @endforelse
                        </div>

                        {{-- Confirmation Summary Box --}}
                        <div x-show="selectedSession" class="mt-6 p-4 bg-primary-container/5 rounded-xl border border-primary-container/25">
                            <h4 class="text-xs font-bold text-on-surface uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-primary text-[18px]">verified</span>
                                Xác nhận thông tin học thử
                            </h4>
                            <ul class="space-y-1 text-xs text-on-surface-variant">
                                <li><span class="text-on-surface-variant inline-block w-28">Khách hàng:</span> <strong class="text-on-surface" x-text="document.getElementById('trial_customer_name')?.value || 'Chưa nhập'"></strong></li>
                                <li><span class="text-on-surface-variant inline-block w-28">Lớp đăng ký:</span> <strong class="text-on-surface" x-text="selectedClassName"></strong></li>
                                <li><span class="text-on-surface-variant inline-block w-28">Thời gian buổi:</span> <strong class="text-primary font-bold" x-text="selectedSession"></strong></li>
                                <li><span class="text-on-surface-variant inline-block w-28">Chi nhánh:</span> <strong class="text-on-surface">{{ $customerBranch }}</strong></li>
                            </ul>
                        </div>
                    </div>
                </div>

                {{-- Footer Actions --}}
                <div class="p-4 md:p-6 border-t border-surface-container-highest bg-surface-container-low flex justify-end gap-3">
                    {{-- Step 1 Buttons --}}
                    <template x-if="step === 1">
                        <div class="flex gap-2">
                            <x-ui.button variant="secondary" :href="route('classes.create')">Bỏ qua</x-ui.button>
                            <x-ui.button x-on:click="step = 2" x-bind:disabled="!selectedClassId">Tiếp theo →</x-ui.button>
                        </div>
                    </template>

                    {{-- Step 2 Buttons --}}
                    <template x-if="step === 2">
                        <div class="flex gap-2">
                            <x-ui.button variant="secondary" x-on:click="step = 1">← Quay lại</x-ui.button>
                            <x-ui.button type="submit" x-bind:disabled="!selectedSession">Xác nhận đặt lịch học thử</x-ui.button>
                        </div>
                    </template>
                </div>
            </form>
        </div>

        {{-- Recent Trial Bookings (Database Backed) --}}
        @if($bookings->isNotEmpty())
            <x-ui.data-table>
                <x-slot:header>
                    <h3 class="text-sm font-bold text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[18px]">history</span>
                        Lịch sử khách học thử đã xác nhận gần đây
                    </h3>
                    <span class="text-xs text-on-surface-variant font-mono">{{ $bookings->count() }} lượt</span>
                </x-slot:header>
                    <table class="text-xs">
                        <thead>
                            <tr>
                                <th>Mã phiếu</th>
                                <th>Khách hàng</th>
                                <th>Lớp học</th>
                                <th>Buổi học</th>
                                <th>Thời gian đặt</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($bookings as $b)
                                <tr>
                                    <td class="font-mono font-bold text-on-surface-variant">{{ $b->record_code }}</td>
                                    <td class="font-bold">{{ $b->data['customer_name'] ?? '—' }}</td>
                                    <td>{{ $b->data['class_name'] ?? '—' }}</td>
                                    <td class="text-primary font-medium">{{ $b->data['session_time'] ?? '—' }}</td>
                                    <td class="text-on-surface-variant">{{ $b->created_at->format('d/m/Y H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
            </x-ui.data-table>
        @endif
    </div>
</x-app-layout>
