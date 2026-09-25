<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 bg-orange-100 rounded-xl flex items-center justify-center text-primary">
                    <span class="material-symbols-outlined text-[24px]">assignment_ind</span>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight">Giao chặng học cho giáo viên</h1>
                    <p class="text-xs text-gray-500">Thiết lập quyền truy cập giáo trình theo từng chặng học cho giáo viên của từng lớp.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('teacher-portal.shortcut', '08_xem_tai_lieu_giao_trinh') }}" target="_blank" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container text-white text-xs font-semibold shadow-sm hover:bg-primary-hover transition">
                    <span class="material-symbols-outlined text-[18px]">menu_book</span>
                    <span>Cổng GV xem tài liệu (Bước #4)</span>
                </a>
            </div>
        </div>
    </x-slot>

    @include('syllabus.partials.flow-header', ['activeStep' => 3])

    <!-- 2-Column Bento Layout -->
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
        <!-- Section 1: Form Giao chặng mới (Left Column) -->
        <aside class="xl:col-span-4 space-y-6">
            <section class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
                <div class="flex items-center gap-2 mb-5 pb-3 border-b border-gray-100">
                    <span class="material-symbols-outlined text-primary">add_task</span>
                    <h2 class="text-sm font-bold text-gray-900">Thiết lập chặng mới cho GV</h2>
                </div>

                <form action="{{ route('syllabus.assignments.store') }}" method="POST" class="space-y-4">
                    @csrf
                    <!-- Chọn Giáo trình / Lớp học -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">Chọn Giáo trình / Khóa học <span class="text-rose-500">*</span></label>
                        <div class="relative">
                            <select name="curriculum_id" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 appearance-none bg-white focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none pr-8">
                                <option disabled selected value="">Chọn giáo trình áp dụng...</option>
                                @foreach ($curriculums as $c)
                                    <option value="{{ $c->id }}">{{ $c->title }} ({{ $c->code }})</option>
                                @endforeach
                            </select>
                            <span class="material-symbols-outlined absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400 text-[18px]">auto_stories</span>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">Lớp áp dụng <span class="text-rose-500">*</span></label>
                        <select name="class_id" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 bg-white">
                            <option disabled selected value="">Chọn lớp...</option>
                            @foreach ($classes as $class)
                                <option value="{{ $class->id }}" @selected((string) old('class_id') === (string) $class->id)>{{ $class->name }} ({{ $class->code }})</option>
                            @endforeach
                        </select>
                        @error('class_id') <p class="text-[11px] text-rose-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <!-- Chọn Giáo viên -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">Chọn Giáo viên phụ trách <span class="text-rose-500">*</span></label>
                        <div class="relative">
                            <select name="user_id" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 appearance-none bg-white focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none pr-8 font-medium">
                                <option disabled selected value="">Chọn giáo viên phân quyền...</option>
                                @foreach ($teachers as $tc)
                                    <option value="{{ $tc->id }}">{{ $tc->name }} (ID: GV-{{ str_pad($tc->id, 3, '0', STR_PAD_LEFT) }})</option>
                                @endforeach
                            </select>
                            <span class="material-symbols-outlined absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400 text-[18px]">person</span>
                        </div>
                    </div>

                    <!-- Chặng học phân công -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">Nội dung / Chặng học được giao <span class="text-rose-500">*</span></label>
                        <input type="text" name="assigned_chapters" placeholder="Chặng 1: Xây dựng nền tảng (Buổi 1 - 10)" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none" />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">Tên chặng <span class="text-rose-500">*</span></label>
                        <input type="text" name="stage_name" placeholder="Chặng 1 · Foundation" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5" />
                    </div>

                    <!-- Ngày hoàn thành / Deadline -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">Thời hạn hoàn thành (Deadline) <span class="text-rose-500">*</span></label>
                        <input type="date" name="deadline" value="{{ date('Y-m-d', strtotime('+14 days')) }}" required class="w-full text-xs rounded-xl border border-gray-200 p-2.5 focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none font-mono" />
                    </div>

                    <!-- Alert Banner (R19) -->
                    <div class="bg-amber-50/70 border-l-4 border-primary-container p-3 rounded-r-xl flex gap-2.5 text-xs text-amber-950">
                        <span class="material-symbols-outlined text-primary shrink-0 text-[18px]">info</span>
                        <div>
                            <span class="font-bold text-primary block mb-0.5">Lưu ý nghiệp vụ (R19):</span>
                            Mỗi LỚP HỌC chỉ được giao duy nhất 1 chặng học có hiệu lực tại một thời điểm. Hãy bấm "Hoàn thành" ở chặng hiện tại của lớp trước khi giao chặng kế tiếp.
                        </div>
                    </div>

                    <!-- Action Button -->
                    <button type="submit" class="w-full py-2.5 px-4 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition-all flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">send</span>
                        <span>Xác nhận giao chặng</span>
                    </button>
                </form>
            </section>

            <!-- Support Card -->
            <div class="bg-gradient-to-br from-orange-50 to-amber-50 rounded-2xl p-5 border border-orange-200/60 relative overflow-hidden group">
                <div class="relative z-10">
                    <h4 class="text-xs font-bold text-primary mb-1 uppercase tracking-wider">Cần hỗ trợ phân quyền?</h4>
                    <p class="text-xs text-gray-600 leading-relaxed">Liên hệ bộ phận Học thuật hoặc Kỹ thuật nếu bạn cần cấu hình quyền đặc biệt cho ca dạy thay hoặc trợ giảng.</p>
                </div>
                <span class="material-symbols-outlined absolute -right-3 -bottom-3 text-[80px] text-primary/10 group-hover:scale-110 transition-transform pointer-events-none">contact_support</span>
            </div>
        </aside>

        <!-- Section 2: Danh sách lịch sử giao chặng (Right Column) -->
        <div class="xl:col-span-8 flex flex-col gap-6">
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col flex-grow">
                <!-- Header with Search & Filter -->
                <div class="px-5 py-3.5 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3 bg-gray-50/50">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">history_edu</span>
                        <h2 class="text-sm font-bold text-gray-900">Lịch sử phân quyền chặng học</h2>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-gray-200 text-gray-700">{{ $assignments->total() }} lượt giao</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="relative">
                            <input type="text" id="assignSearch" onkeyup="filterAssignTable()" class="pl-9 pr-3 py-1.5 bg-white border border-gray-200 rounded-xl text-xs focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none w-56" placeholder="Tìm tên giáo viên, lớp..." />
                            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-[18px]">search</span>
                        </div>
                    </div>
                </div>

                <!-- Assignments Table -->
                <div class="overflow-x-auto flex-grow custom-scrollbar">
                    <table class="w-full text-left border-collapse text-xs" id="assignTable">
                        <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[10px]">
                            <tr>
                                <th class="py-3 px-4">Giáo trình / Lớp</th>
                                <th class="py-3 px-4">Giáo viên phụ trách</th>
                                <th class="py-3 px-4">Chặng học</th>
                                <th class="py-3 px-4">Thời hạn</th>
                                <th class="py-3 px-4 text-center">Tiến độ</th>
                                <th class="py-3 px-4 text-right">Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                            @forelse ($assignments as $as)
                                <tr class="hover:bg-gray-50/80 transition-colors group">
                                    <td class="py-3.5 px-4 font-semibold text-gray-900">
                                        <div class="flex items-center gap-2">
                                            <span class="material-symbols-outlined text-primary text-[18px]">school</span>
                                            <span>{{ $as->curriculum?->title ?? 'IELTS Intensive' }}<small class="block text-gray-400">{{ $as->classModel?->name ?? 'Chưa gắn lớp' }}</small></span>
                                        </div>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <div class="flex items-center gap-2.5">
                                            <div class="w-7 h-7 rounded-full bg-orange-100 text-primary font-bold flex items-center justify-center text-[11px]">
                                                {{ substr($as->teacher?->name ?? 'G', 0, 1) }}
                                            </div>
                                            <div>
                                                <p class="font-bold text-gray-900">{{ $as->teacher?->name ?? 'Giáo viên' }}</p>
                                                <p class="text-[10px] text-gray-400 font-mono">ID: GV-{{ str_pad($as->user_id, 3, '0', STR_PAD_LEFT) }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <span class="px-2.5 py-1 rounded-lg bg-orange-50 text-primary font-medium text-[11px] border border-orange-200/60">
                                            {{ $as->stage_name ? $as->stage_name.' · ' : '' }}{{ $as->assigned_chapters }}
                                        </span>
                                    </td>
                                    <td class="py-3.5 px-4 font-mono text-gray-500 whitespace-nowrap">
                                        {{ $as->deadline ? $as->deadline->format('d/m/Y') : '—' }}
                                    </td>
                                    <td class="py-3.5 px-4 text-center whitespace-nowrap">
                                        <div class="w-24 bg-gray-100 rounded-full h-2 mx-auto overflow-hidden">
                                            <div class="bg-primary-container h-2 rounded-full" style="width: {{ max($as->progress_percent, 15) }}%"></div>
                                        </div>
                                        <span class="text-[10px] text-gray-400 font-mono mt-1 block">{{ $as->progress_percent }}%</span>
                                    </td>
                                    <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                        @if ($as->status === 'in_progress')
                                            <div class="flex items-center justify-end gap-2">
                                                <x-ui.badge color="success">Đang áp dụng</x-ui.badge>
                                                @can('syllabus.manage')
                                                    <form method="POST" action="{{ route('syllabus.assignments.complete', $as->id) }}" data-confirm="Đánh dấu hoàn thành chặng {{ $as->stage_name }} của lớp {{ $as->classModel?->name }}?">
                                                        @csrf
                                                        <x-ui.button type="submit" variant="secondary" size="sm" icon="task_alt">Hoàn thành</x-ui.button>
                                                    </form>
                                                @endcan
                                            </div>
                                        @elseif ($as->status === 'completed')
                                            <x-ui.badge color="neutral">Đã hoàn thành</x-ui.badge>
                                        @else
                                            <x-ui.badge color="warning">{{ $as->status }}</x-ui.badge>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center py-12 text-gray-400 text-xs">Chưa có dữ liệu phân quyền chặng học nào.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-gray-100"><x-ui.pagination :paginator="$assignments" unit="lượt giao" /></div>
            </section>
        </div>
    </div>

    <script>
        function filterAssignTable() {
            const input = document.getElementById('assignSearch');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('assignTable');
            const tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) {
                const text = tr[i].textContent || tr[i].innerText;
                if (text.toLowerCase().indexOf(filter) > -1) {
                    tr[i].style.display = '';
                } else {
                    tr[i].style.display = 'none';
                }
            }
        }
    </script>
</x-app-layout>
