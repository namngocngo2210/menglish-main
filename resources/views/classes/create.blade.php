<x-app-layout>
    <x-ui.page-header title="Tạo lớp mới (Flow 1 — Bước #2)" icon="group_add" :back="route('classes.trial-booking')">
        <x-slot:badges>
            <x-ui.badge color="secondary" :dot="false" :pill="true">Học vụ / Quản trị</x-ui.badge>
        </x-slot:badges>
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="list" :href="route('classes.academic-list')">Quay lại danh sách lớp</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>


    <div class="max-w-4xl mx-auto space-y-6">

        {{-- Main Form Card --}}
        <form action="{{ route('classes.store') }}" method="POST" class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm overflow-hidden divide-y divide-surface-container-highest">
            @csrf

            {{-- Khối 1: Thông tin cơ bản & Phân loại lớp (Bắt buộc) --}}
            <div class="p-6 md:p-8 space-y-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-primary-container"></div>
                        <h2 class="text-base font-bold text-on-surface uppercase tracking-wide">
                            1. Thông tin cơ bản &amp; Phân loại
                        </h2>
                    </div>
                    <span class="text-xs text-on-surface-variant/70 font-medium italic">
                        (<span class="text-error font-bold">*</span>) Trường bắt buộc nhập
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    {{-- Tên lớp (Bắt buộc) --}}
                    <div class="md:col-span-8">
                        <x-ui.input id="ten_lop" name="ten_lop" label="Tên lớp học" required placeholder="VD: ENG-B1 · IELTS Căn Bản K26" class="text-xs"
                                    hint="Tên hiển thị rõ ràng trên sổ điểm danh và cổng giáo viên." />
                    </div>

                    {{-- Mã lớp (Tùy chọn) --}}
                    <div class="md:col-span-4">
                        <x-ui.input id="ma_lop" name="ma_lop" label="Mã lớp (Tùy chọn)" placeholder="VD: ENG-B1-K26" class="text-xs font-mono uppercase"
                                    hint="Để trống hệ thống sẽ tự sinh theo quy tắc." />
                    </div>

                    {{-- Chi nhánh (Bắt buộc) --}}
                    <div class="md:col-span-4">
                        <x-ui.select id="chi_nhanh" name="chi_nhanh" label="Chi nhánh đào tạo" required class="text-xs cursor-pointer">
                            <option value="" disabled {{ old('chi_nhanh') ? '' : 'selected' }}>-- Chọn chi nhánh --</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ old('chi_nhanh') == $b->id ? 'selected' : '' }}>
                                    {{ $b->name }} ({{ $b->code }})
                                </option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    {{-- Chương trình (Bắt buộc) --}}
                    <div class="md:col-span-4">
                        <x-ui.select id="chuong_trinh" name="chuong_trinh" label="Chương trình học" required class="text-xs cursor-pointer">
                            <option value="" disabled {{ old('chuong_trinh') ? '' : 'selected' }}>-- Chọn chương trình học --</option>
                            @foreach($courses as $c)
                                <option value="{{ $c->name }}" data-fee="{{ $c->tuition_fee }}" {{ old('chuong_trinh') === $c->name ? 'selected' : '' }}>{{ $c->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    {{-- Cấp độ (Bắt buộc) --}}
                    <div class="md:col-span-4">
                        <x-ui.select id="cap_do" name="cap_do" label="Cấp độ" required class="text-xs cursor-pointer">
                            <option value="" disabled {{ old('cap_do') ? '' : 'selected' }}>-- Chọn cấp độ --</option>
                            @foreach($levels as $lvl)
                                <option value="{{ $lvl->code }}" {{ old('cap_do') === $lvl->code ? 'selected' : '' }}>{{ $lvl->name }} ({{ $lvl->target }})</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    {{-- Sĩ số tối đa (Bắt buộc) --}}
                    <div class="md:col-span-4">
                        <x-ui.field label="Sĩ số tối đa" name="si_so_toi_da" for="si_so_toi_da" required hint="Giới hạn số học viên xếp lớp tối đa.">
                            <div class="relative">
                                <input type="number"
                                       id="si_so_toi_da"
                                       name="si_so_toi_da"
                                       min="1"
                                       max="100"
                                       value="{{ old('si_so_toi_da') }}"
                                       placeholder="VD: 16"
                                       required
                                       class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm pr-16 text-xs text-on-surface placeholder:text-on-surface-variant/60 focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                                <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-[11px] text-on-surface-variant/70 font-medium">
                                    học viên
                                </div>
                            </div>
                        </x-ui.field>
                    </div>

                    {{-- Ngưỡng khai giảng (số học viên tối thiểu để mở lớp) --}}
                    <div class="md:col-span-4">
                        <x-ui.field label="Ngưỡng khai giảng" name="min_students" for="min_students" hint="Số học viên tối thiểu để mở lớp; không vượt sĩ số tối đa.">
                            <div class="relative">
                                <input type="number"
                                       id="min_students"
                                       name="min_students"
                                       min="1"
                                       max="100"
                                       value="{{ old('min_students', 6) }}"
                                       class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm pr-16 text-xs text-on-surface placeholder:text-on-surface-variant/60 focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition">
                                <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-[11px] text-on-surface-variant/70 font-medium">
                                    học viên
                                </div>
                            </div>
                        </x-ui.field>
                    </div>

                    {{-- Trạng thái khởi tạo (Readonly indicator) --}}
                    <div class="md:col-span-12 flex items-center">
                        <div class="w-full bg-surface-container-low border border-surface-container-highest rounded-xl px-4 py-2.5 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] font-bold text-on-surface-variant uppercase tracking-wider">Trạng thái lớp ban đầu:</span>
                                <x-ui.badge color="warning">Chưa cấu hình lịch (Khởi tạo)</x-ui.badge>
                            </div>
                            <span class="text-[11px] text-on-surface-variant/70 italic">Tự động kích hoạt khi xếp ca ở TKB</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Khối 2: Phòng học & Nhân sự giảng dạy (Tùy chọn - có thể để trống gán sau) --}}
            <div class="p-6 md:p-8 space-y-6 bg-surface-container-low/40">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-secondary"></div>
                        <h2 class="text-base font-bold text-on-surface uppercase tracking-wide">
                            2. Phòng học &amp; Đội ngũ phụ trách
                        </h2>
                    </div>
                    <x-ui.badge :dot="false">Tùy chọn • Để trống gán sau</x-ui.badge>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    {{-- Phòng học (Tùy chọn) --}}
                    <div class="md:col-span-6">
                        <x-ui.select id="phong_hoc" name="phong_hoc" label="Phòng học dự kiến" class="text-xs cursor-pointer" placeholder="-- Chưa gán phòng (để trống) --"
                                     hint="Danh sách phòng hiện có của trung tâm (dùng chung các chi nhánh)."
                                     :options="['P101' => 'Phòng 101 (Sức chứa 20 - Tầng 1)', 'P202' => 'Phòng 202 (Sức chứa 16 - Tầng 2)', 'P302' => 'Phòng 302 (Sức chứa 18 - Tầng 3)', 'LAB_A' => 'Phòng Lab A (Sức chứa 24 - Tầng 4)', 'LAB_B' => 'Phòng Lab B (Sức chứa 24 - Tầng 4)']" />
                    </div>

                    {{-- Giáo viên chính (Tùy chọn) --}}
                    <div class="md:col-span-6">
                        <x-ui.select id="giao_vien_chinh" name="giao_vien_chinh" label="Giáo viên chính" class="text-xs cursor-pointer" placeholder="-- Chưa gán giáo viên chính (để trống) --"
                                     :options="$teachers->mapWithKeys(fn ($t) => [$t->id => $t->name . ' (' . $t->email . ')'])" />
                    </div>

                    {{-- Trợ giảng (Tùy chọn) --}}
                    <div class="md:col-span-6">
                        <x-ui.select id="tro_giang" name="tro_giang" label="Trợ giảng" class="text-xs cursor-pointer" placeholder="-- Chưa gán trợ giảng (để trống) --"
                                     :options="$assistants->mapWithKeys(fn ($ta) => [$ta->id => $ta->name . ' (' . $ta->email . ')'])" />
                    </div>

                    {{-- Giáo viên nước ngoài (GVNN) (Tùy chọn) --}}
                    <div class="md:col-span-6">
                        <x-ui.select id="giao_vien_nn" name="giao_vien_nn" label="Giáo viên nước ngoài (GVNN)" class="text-xs cursor-pointer" placeholder="-- Không áp dụng hoặc gán sau (để trống) --"
                                     :options="$foreignTeachers->mapWithKeys(fn ($teacher) => [$teacher->id => $teacher->name . ' (' . $teacher->email . ')'])" />
                    </div>
                </div>
            </div>

            {{-- Khối 3: Lên lịch học tự động (Render TKB ngay khi tạo lớp) --}}
            <div class="p-6 md:p-8 space-y-6 bg-surface-container-low/40" x-data="scheduleGenerator()" x-cloak>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-secondary"></div>
                        <h2 class="text-base font-bold text-on-surface uppercase tracking-wide">
                            3. Lên lịch học tự động
                        </h2>
                    </div>
                    <x-ui.badge :dot="false">Tùy chọn • Không render sẽ tạo lớp chờ TKB</x-ui.badge>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    {{-- Start Date --}}
                    <div class="md:col-span-3">
                        <x-ui.date label="Ngày bắt đầu" x-model="startDate" class="text-xs" />
                    </div>
                    {{-- End Date --}}
                    <div class="md:col-span-3">
                        <x-ui.date label="Ngày kết thúc" x-model="endDate" class="text-xs" />
                    </div>
                    {{-- Days of Week --}}
                    <div class="md:col-span-6">
                        <label class="block text-xs font-bold text-on-surface-variant mb-1.5">Ngày học trong tuần</label>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="day in daysOfWeek" :key="day.value">
                                <label class="flex items-center gap-1 bg-surface-container px-3 py-1.5 rounded-lg border border-surface-container-highest cursor-pointer hover:bg-surface-container-high">
                                    <input type="checkbox" :value="day.value" x-model="selectedDays" class="text-primary focus:ring-primary-container rounded">
                                    <span class="text-xs font-medium text-on-surface-variant" x-text="day.label"></span>
                                </label>
                            </template>
                        </div>
                    </div>

                    {{-- Shift config --}}
                    <div class="md:col-span-12">
                        <label class="block text-xs font-bold text-on-surface-variant mb-1.5">Cấu hình ca học</label>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <template x-for="shift in shifts" :key="shift.name">
                                <label class="flex items-center gap-2 bg-surface-container-low p-3 rounded-xl border border-surface-container-highest cursor-pointer hover:bg-secondary/10">
                                    <input type="checkbox" :value="shift.name" x-model="selectedShifts" class="text-secondary focus:ring-secondary rounded">
                                    <div class="flex flex-col">
                                        <span class="text-xs font-bold text-on-surface" x-text="shift.name"></span>
                                        <span class="text-[10px] text-on-surface-variant" x-text="shift.start + ' - ' + shift.end"></span>
                                    </div>
                                </label>
                            </template>
                        </div>
                    </div>

                    <div class="md:col-span-12 flex justify-end">
                        <x-ui.button variant="info" x-on:click="generateSchedule">⚡ Render Thời Khoá Biểu</x-ui.button>
                    </div>
                </div>

                {{-- Generated Schedule Table --}}
                <x-ui.data-table x-show="generatedSessions.length > 0" class="mt-6">
                    <table>
                        <thead>
                            <tr>
                                <th>Ngày</th>
                                <th>Ca học</th>
                                <th>Phòng trống</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(session, index) in generatedSessions" :key="index">
                                <tr>
                                    <td class="text-xs font-medium" x-text="session.dateFormatted"></td>
                                    <td>
                                        <span class="inline-block px-2 py-1 bg-secondary/10 text-secondary font-bold text-[10px] rounded" x-text="session.shift + ' (' + session.start + ' - ' + session.end + ')'"></span>
                                    </td>
                                    <td class="relative">
                                        <div x-show="session.loading" class="text-xs text-on-surface-variant">Đang kiểm tra...</div>
                                        <select x-show="!session.loading" x-model="session.room" class="w-full px-2 py-1 text-xs border border-surface-container-highest rounded focus:ring-primary-container focus:border-primary-container">
                                            <option value="">-- Chọn phòng --</option>
                                            <template x-for="room in allRooms">
                                                <option :value="room" :disabled="session.occupiedRooms.includes(room)" x-text="room + (session.occupiedRooms.includes(room) ? ' (Đã trùng lịch)' : ' (Trống)')"></option>
                                            </template>
                                        </select>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>

                    <input type="hidden" name="schedule_sessions_json" :value="generatedSessions.length > 0 ? JSON.stringify(generatedSessions) : ''">
                    @error('schedule_sessions_json') <p class="text-[11px] text-error px-4 py-2">{{ $message }}</p> @enderror
                </x-ui.data-table>
            </div>

            {{-- Khối 3: Học phí & Ghi chú quản lý (Tùy chọn) --}}
            <div class="p-6 md:p-8 space-y-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-2.5 h-2.5 rounded-full bg-tertiary"></div>
                        <h2 class="text-base font-bold text-on-surface uppercase tracking-wide">
                            4. Học phí &amp; Ghi chú nội bộ
                        </h2>
                    </div>
                    <x-ui.badge :dot="false">Tùy chọn</x-ui.badge>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                    {{-- Học phí (Tùy chọn) --}}
                    <div class="md:col-span-6">
                        <x-ui.field label="Mức học phí niêm yết" name="hoc_phi" for="hoc_phi" hint="Đơn giá trọn khóa trước khi áp dụng ưu đãi/học bổng.">
                            <div class="relative">
                                <input type="number"
                                       id="hoc_phi"
                                       name="hoc_phi"
                                       value="{{ old('hoc_phi') }}"
                                       placeholder="VD: 8500000"
                                       min="0"
                                       step="50000"
                                       class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest pl-md pr-14 py-sm text-xs placeholder:text-on-surface-variant/60 focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container transition font-mono font-bold text-on-surface">
                                <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-xs font-bold text-on-surface-variant/70">
                                    VNĐ
                                </div>
                            </div>
                        </x-ui.field>
                    </div>

                    {{-- Ghi chú (Tùy chọn) --}}
                    <div class="md:col-span-12">
                        <x-ui.textarea id="ghi_chu" name="ghi_chu" label="Ghi chú vận hành (Tùy chọn)" rows="3" class="text-xs"
                                       placeholder="Ghi chú thêm về yêu cầu đầu vào, lớp liên kết doanh nghiệp hoặc lưu ý đặc biệt cho giáo viên phụ trách..." />
                    </div>
                </div>
            </div>

            {{-- Action Footer (Buttons) --}}
            <div class="p-6 bg-surface-container-low border-t border-surface-container-highest flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="text-xs text-on-surface-variant text-center sm:text-left">
                    Sau khi bấm <strong class="text-on-surface">"Lưu lớp học"</strong>, hệ thống sẽ tạo bản ghi lớp và tự động chuyển hướng đến <strong class="text-on-surface">Hồ sơ lớp học</strong>.
                </div>

                <div class="flex items-center gap-3 w-full sm:w-auto">
                    <x-ui.button variant="secondary" :href="route('classes.academic-list')" class="w-full sm:w-auto">Hủy bỏ</x-ui.button>
                    <x-ui.button type="submit" icon="save" class="w-full sm:w-auto">Lưu &amp; Chuyển sang Hồ sơ lớp</x-ui.button>
                </div>
            </div>
        </form>
    </div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const courseSelect = document.getElementById('chuong_trinh');
        const tuitionInput = document.getElementById('hoc_phi');

        if (courseSelect && tuitionInput) {
            courseSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const fee = selectedOption.getAttribute('data-fee');
                if (fee) {
                    // Điền số thô (VD: 8500000) — server validate 'numeric', không nhận dấu chấm ngăn cách hàng nghìn.
                    const parsed = parseFloat(fee);
                    tuitionInput.value = isNaN(parsed) ? '' : String(parsed);
                } else {
                    tuitionInput.value = '';
                }
            });
        }
    });
</script>

@push('scripts')
    {{-- Alpine đã được nạp toàn cục qua bundle Vite (resources/js/app.js);
         nạp thêm CDN ở đây gây double-init cho schedule generator. --}}
    <script>
        function scheduleGenerator() {
            return {
                startDate: '',
                endDate: '',
                selectedDays: [],
                selectedShifts: [],
                generatedSessions: (function () {
                    // Khôi phục thời khóa biểu đã render khi server trả lỗi validation,
                    // tránh bắt người dùng render lại toàn bộ từ đầu.
                    const previous = @js(old('schedule_sessions_json'));
                    if (!previous) return [];
                    try {
                        return JSON.parse(previous).map(function (session) {
                            return Object.assign({}, session, {
                                dateFormatted: session.dateFormatted || new Date(session.date + 'T00:00:00').toLocaleDateString('vi-VN'),
                                loading: false,
                                occupiedRooms: session.occupiedRooms || [],
                            });
                        });
                    } catch (e) {
                        return [];
                    }
                })(),
                allRooms: ['P101', 'P202', 'P302', 'LAB_A', 'LAB_B'],
                daysOfWeek: [
                    { value: '1', label: 'T2' },
                    { value: '2', label: 'T3' },
                    { value: '3', label: 'T4' },
                    { value: '4', label: 'T5' },
                    { value: '5', label: 'T6' },
                    { value: '6', label: 'T7' },
                    { value: '0', label: 'CN' }
                ],
                shifts: [
                    { name: 'Ca 1', start: '08:00', end: '09:30' },
                    { name: 'Ca 2', start: '09:30', end: '11:00' },
                    { name: 'Ca 3', start: '14:00', end: '15:30' },
                    { name: 'Ca 4', start: '15:30', end: '17:00' },
                    { name: 'Ca Tối 1', start: '18:00', end: '19:30' },
                    { name: 'Ca Tối 2', start: '19:30', end: '21:00' }
                ],

                async generateSchedule() {
                    if (!this.startDate || !this.endDate || this.selectedDays.length === 0 || this.selectedShifts.length === 0) {
                        alert('Vui lòng chọn đầy đủ Ngày bắt đầu, Ngày kết thúc, Ngày học và Ca học!');
                        return;
                    }

                    let start = new Date(this.startDate);
                    let end = new Date(this.endDate);
                    let branchId = document.getElementById('chi_nhanh').value;

                    this.generatedSessions = [];
                    let shiftsToApply = this.shifts.filter(s => this.selectedShifts.includes(s.name));

                    let currentDate = new Date(start);
                    while (currentDate <= end) {
                        let day = currentDate.getDay().toString();
                        if (this.selectedDays.includes(day)) {
                            let dateStr = currentDate.toISOString().split('T')[0];
                            let dateFormatted = currentDate.toLocaleDateString('vi-VN');

                            for (let shift of shiftsToApply) {
                                let session = {
                                    date: dateStr,
                                    dateFormatted: dateFormatted,
                                    shift: shift.name,
                                    start: shift.start,
                                    end: shift.end,
                                    room: '',
                                    occupiedRooms: [],
                                    loading: true
                                };

                                this.generatedSessions.push(session);
                                this.checkRoomAvailability(session, branchId);
                            }
                        }
                        currentDate.setDate(currentDate.getDate() + 1);
                    }
                },

                async checkRoomAvailability(session, branchId) {
                    try {
                        const response = await fetch('{{ route('classes.check-availability') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                branch_id: branchId,
                                date: session.date,
                                start_time: session.start,
                                end_time: session.end
                            })
                        });

                        const data = await response.json();
                        session.occupiedRooms = data.occupied_rooms || [];

                        // Auto assign first available room
                        let freeRoom = this.allRooms.find(r => !session.occupiedRooms.includes(r));
                        if (freeRoom) {
                            session.room = freeRoom;
                        }
                    } catch (e) {
                        console.error(e);
                    } finally {
                        session.loading = false;
                    }
                }
            }
        }
    </script>
@endpush

</x-app-layout>
