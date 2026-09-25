@php
    $canEdit = auth()->user()?->can('student.update') ?? false;
    $viewerRole = auth()->user()?->getRoleNames()->first();
    $viewerRoleLabel = $viewerRole ? \App\Helpers\AclHelper::roleLabel($viewerRole) : 'Người dùng';
    $sessionStatusLabels = ['scheduled' => 'Đã lên lịch', 'completed' => 'Đã dạy', 'cancelled' => 'Đã hủy'];
    $attendanceTones = ['present' => 'text-emerald-700', 'late' => 'text-amber-600', 'excused' => 'text-sky-700', 'absent' => 'text-rose-600'];
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="space-y-1">
                <nav class="flex items-center gap-1.5 text-xs text-slate-500 font-medium">
                    <a href="{{ route('dashboard') }}" class="hover:text-primary transition">Trang chủ</a>
                    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                    <a href="{{ route('students.index') }}" class="hover:text-primary transition">Hồ sơ học sinh</a>
                    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                    <span class="text-primary font-bold">Chi tiết</span>
                </nav>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-orange-600">account_circle</span>
                    Chi Tiết Hồ Sơ Học Sinh: {{ $student->name }}
                </h1>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('students.scoped', $student->id) }}" class="px-3.5 py-2 rounded-xl border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 text-xs font-semibold shadow-2xs transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">admin_panel_settings</span>
                    <span>Xem theo phân quyền</span>
                </a>
                <a href="{{ route('students.index') }}" class="px-3.5 py-2 rounded-xl border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 text-xs font-semibold shadow-2xs transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">arrow_back</span>
                    <span>Danh sách học sinh</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">

        <!-- ────────────────────────────────────────────── -->
        <!-- SECTION 1: THÔNG TIN CƠ BẢN (BENTO PROFILE GRID) -->
        <!-- ────────────────────────────────────────────── -->
        <section class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- Profile Info Card (Left Col 4) -->
            <div class="lg:col-span-4 bg-white border border-gray-200 rounded-2xl p-6 flex flex-col items-center text-center space-y-4 shadow-sm">
                <div class="relative group">
                    <div class="w-32 h-32 rounded-full border-4 border-orange-200 bg-gradient-to-br from-orange-100 to-amber-50 text-orange-600 flex items-center justify-center font-black text-4xl shadow-md overflow-hidden">
                        {{ Str::substr($student->name, 0, 1) }}
                    </div>
                    <div class="absolute bottom-1 right-1 bg-orange-600 text-white p-1.5 rounded-full shadow-lg">
                        <span class="material-symbols-outlined text-[16px]">school</span>
                    </div>
                </div>

                <div class="space-y-1">
                    <h2 class="text-xl font-black text-gray-900">{{ $student->name }}</h2>
                    <p class="text-gray-500 text-xs flex items-center justify-center gap-1">
                        <span class="material-symbols-outlined text-[16px] text-orange-500">cake</span>
                        <span>
                            @if ($student->dob)
                                {{ $student->dob->format('d/m/Y') }} ({{ $student->dob->age }} tuổi)
                            @else
                                Chưa cập nhật ngày sinh
                            @endif
                        </span>
                    </p>
                    <p class="text-gray-500 text-xs flex items-center justify-center gap-1">
                        <span class="material-symbols-outlined text-[16px] text-orange-500">call</span>
                        <span class="font-mono font-bold text-gray-800">{{ $student->phone }}</span>
                    </p>
                    @if ($student->email)
                        <p class="text-gray-400 text-xs font-mono">{{ $student->email }}</p>
                    @endif
                </div>

                <div class="pt-4 w-full border-t border-gray-100 grid grid-cols-2 gap-4 text-xs">
                    <div class="text-center p-2 rounded-xl bg-slate-50 border border-slate-100">
                        <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Mã học sinh</p>
                        <p class="font-bold text-orange-600 font-mono mt-0.5">{{ $student->code }}</p>
                    </div>
                    <div class="text-center p-2 rounded-xl bg-slate-50 border border-slate-100">
                        <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Ngày nhập học</p>
                        <p class="font-bold text-gray-800 font-mono mt-0.5">{{ $student->created_at ? $student->created_at->format('d/m/Y') : '—' }}</p>
                    </div>
                </div>
            </div>

            <!-- Edit Form Card (Right Col 8) -->
            <div class="lg:col-span-8 bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                    <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-orange-600 text-base">{{ $canEdit ? 'edit_document' : 'badge' }}</span>
                        <span>{{ $canEdit ? 'Chỉnh sửa thông tin học viên' : 'Thông tin học viên' }}</span>
                    </h3>
                    <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 text-[11px] px-2.5 py-0.5 rounded-full font-bold">
                        Vai trò: {{ $viewerRoleLabel }}
                    </span>
                </div>

                @if (! $canEdit)
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                        <div><dt class="text-[11px] font-bold text-gray-500">Họ và tên</dt><dd class="font-bold text-gray-900">{{ $student->name }}</dd></div>
                        <div><dt class="text-[11px] font-bold text-gray-500">Số điện thoại</dt><dd class="font-mono font-bold text-gray-900">{{ $student->phone }}</dd></div>
                        <div><dt class="text-[11px] font-bold text-gray-500">Email liên hệ</dt><dd class="text-gray-800">{{ $student->email ?: '—' }}</dd></div>
                        <div><dt class="text-[11px] font-bold text-gray-500">Mục tiêu học tập</dt><dd class="text-gray-800">{{ $student->target ?: '—' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-[11px] font-bold text-gray-500">Địa chỉ liên hệ</dt><dd class="text-gray-800">{{ $student->address ?: '—' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="text-[11px] font-bold text-gray-500">Ghi chú</dt><dd class="text-gray-800 whitespace-pre-line">{{ $student->notes ?: '—' }}</dd></div>
                    </dl>
                @else
                <form action="{{ route('students.update', $student->id) }}" method="POST" class="space-y-4" data-testid="student-edit-form">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                        <div>
                            <label class="block font-bold text-gray-700 mb-1 text-[11px]">Họ và tên <span class="text-rose-500">*</span></label>
                            <input type="text" name="name" value="{{ old('name', $student->name) }}" required class="w-full text-xs font-bold rounded-xl border border-gray-300 p-2.5 bg-slate-50/50 focus:ring-primary-container focus:border-primary-container" />
                        </div>
                        <div>
                            <label class="block font-bold text-gray-700 mb-1 text-[11px]">Số điện thoại <span class="text-rose-500">*</span></label>
                            <input type="tel" name="phone" value="{{ old('phone', $student->phone) }}" required class="w-full text-xs font-mono font-bold rounded-xl border border-gray-300 p-2.5 bg-slate-50/50 focus:ring-primary-container focus:border-primary-container" />
                        </div>
                        <div>
                            <label class="block font-bold text-gray-700 mb-1 text-[11px]">Email liên hệ</label>
                            <input type="email" name="email" value="{{ old('email', $student->email) }}" placeholder="hocvien@menglish.edu.vn" class="w-full text-xs rounded-xl border border-gray-300 p-2.5 bg-slate-50/50 focus:ring-primary-container focus:border-primary-container" />
                        </div>
                        <div>
                            <label class="block font-bold text-gray-700 mb-1 text-[11px]">Mục tiêu học tập</label>
                            <input type="text" name="target" value="{{ old('target', $student->target) }}" placeholder="VD: IELTS 6.5, Cambridge Starters..." class="w-full text-xs font-semibold rounded-xl border border-gray-300 p-2.5 bg-slate-50/50 focus:ring-primary-container focus:border-primary-container" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-gray-700 mb-1 text-[11px]">Địa chỉ liên hệ</label>
                            <textarea name="address" rows="2" placeholder="Nhập địa chỉ của học viên..." class="w-full text-xs rounded-xl border border-gray-300 p-2.5 bg-slate-50/50 focus:ring-primary-container focus:border-primary-container">{{ old('address', $student->address) }}</textarea>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-gray-700 mb-1 text-[11px]">Ghi chú đặc biệt / Lời dặn phụ huynh</label>
                            <textarea name="notes" rows="2" placeholder="Nhập ghi chú về học sinh (ví dụ: dị ứng, sở thích, mục tiêu học tập...)" class="w-full text-xs rounded-xl border border-gray-300 p-2.5 bg-slate-50/50 focus:ring-primary-container focus:border-primary-container">{{ old('notes', $student->notes) }}</textarea>
                        </div>
                    </div>

                    <div class="pt-2 border-t border-gray-100 flex items-center justify-end gap-3">
                        <a href="{{ route('students.show', $student->id) }}" class="px-4 py-2 border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 rounded-xl text-xs font-semibold transition">Hủy</a>
                        <button type="submit" class="px-5 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-xl text-xs font-bold shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                            <span class="material-symbols-outlined text-[16px]">save</span>
                            <span>Lưu thay đổi</span>
                        </button>
                    </div>
                </form>
                @endif
            </div>

        </section>

        <!-- ────────────────────────────────────────────── -->
        <!-- SECTION 2: TRẠNG THÁI VÒNG ĐỜI (LIFECYCLE BAR) -->
        <!-- ────────────────────────────────────────────── -->
        <section class="bg-white border border-gray-200 rounded-2xl p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-sm">
            <div class="flex items-center gap-6">
                <div class="flex flex-col">
                    <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Trạng thái hiện tại</span>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="px-3 py-1 rounded-full border font-bold text-xs flex items-center gap-1.5 {{ $student->status_badge }}">
                            <span class="w-2 h-2 rounded-full bg-current {{ $student->status === 'studying' ? 'animate-pulse' : '' }}"></span>
                            <span>{{ $student->status_label }}</span>
                        </span>
                    </div>
                </div>

                <div class="h-8 w-px bg-gray-200 hidden sm:block"></div>

                <div class="flex flex-col">
                    <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Thời gian cập nhật</span>
                    <span class="text-xs font-semibold text-gray-700 mt-1 font-mono">
                        {{ $student->updated_at ? $student->updated_at->format('d/m/Y H:i') : '—' }}
                    </span>
                </div>
            </div>

            <!-- Lifecycle Status Switcher Form -->
            @can('student.change_status')
            <form action="{{ route('students.status.update', $student->id) }}" method="POST" class="relative">
                @csrf
                @method('PUT')

                <div class="flex items-center gap-2">
                    <label class="text-[11px] font-bold text-gray-600 uppercase">Đổi trạng thái:</label>
                    <select name="status" data-current="{{ $student->status }}" onchange="if (this.value === 'dropped' && ! confirm('Chuyển sang Thôi học sẽ đưa học viên ra khỏi danh sách lớp đang học (vẫn giữ lịch sử). Tiếp tục?')) { this.value = this.dataset.current; return; } this.form.submit()" class="text-xs font-bold rounded-xl border border-gray-300 bg-slate-50 py-2 px-3 focus:ring-primary-container focus:border-primary-container cursor-pointer">
                        @foreach (\App\Models\Student::STATUSES as $statusKey => $statusLabel)
                            <option value="{{ $statusKey }}" @selected($student->status === $statusKey)>{{ $statusLabel }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
            @endcan
        </section>

        <!-- ────────────────────────────────────────────── -->
        <!-- SECTION 3: LỘ TRÌNH HỌC TẬP & DANH SÁCH BUỔI HỌC -->
        <!-- Nguồn: buổi học thật (class_sessions) của các lớp học viên đang theo + điểm danh của chính học viên. -->
        <!-- ────────────────────────────────────────────── -->
        <section class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden" data-section="roadmap">
            <div class="p-4 sm:p-5 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-slate-50/70">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-orange-600 text-xl">auto_stories</span>
                    <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900">Lộ trình học tập &amp; Danh sách buổi học</h3>
                </div>
                <span class="text-xs text-gray-500">{{ $sessions->count() }} buổi</span>
            </div>

            @if ($sessions->isEmpty())
                <x-ui.empty-state icon="event_busy" title="Chưa có buổi học nào"
                    :description="$classes->isEmpty() ? 'Học viên chưa được xếp lớp nên chưa có lộ trình buổi học.' : 'Lớp của học viên chưa được sinh lịch buổi học.'" />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs min-w-[760px]">
                        <thead>
                            <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                                <th class="py-3 px-4 text-center w-16">Buổi</th>
                                <th class="py-3 px-4">Ngày học / Thời gian</th>
                                <th class="py-3 px-4">Lớp</th>
                                <th class="py-3 px-4">Giáo viên phụ trách</th>
                                <th class="py-3 px-4">Trạng thái buổi</th>
                                <th class="py-3 px-4 text-right">Điểm danh</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                            @foreach ($sessions as $idx => $session)
                                @php $att = $attendanceBySession->get($session->id); @endphp
                                <tr class="hover:bg-blue-50/20 transition">
                                    <td class="py-3 px-4 text-center font-bold font-mono text-gray-900">#{{ $idx + 1 }}</td>
                                    <td class="py-3 px-4">
                                        <div class="font-bold text-gray-900">{{ $session->date?->format('d/m/Y') ?? '—' }}</div>
                                        <div class="text-[11px] font-mono text-gray-500">
                                            {{ $session->start_time?->format('H:i') }}@if ($session->end_time) – {{ $session->end_time->format('H:i') }}@endif
                                            @if ($session->shift_name) · {{ $session->shift_name }} @endif
                                        </div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="font-bold text-gray-900">{{ $session->classModel?->name ?? '—' }}</span>
                                        @if ($session->type === \App\Models\ClassSession::TYPE_MAKEUP)
                                            <span class="ml-1 text-[10px] font-bold text-amber-700">Học bù</span>
                                        @elseif ($session->type === \App\Models\ClassSession::TYPE_SUPPORT)
                                            <span class="ml-1 text-[10px] font-bold text-sky-700">Phụ đạo</span>
                                        @endif
                                        @if ($session->room)
                                            <p class="text-[11px] text-gray-500">Phòng {{ $session->room }}</p>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4 font-semibold text-gray-800">{{ $session->teacher?->name ?? $session->classModel?->teacher?->name ?? 'Chưa gán GV' }}</td>
                                    <td class="py-3 px-4">{{ $sessionStatusLabels[$session->status] ?? $session->status }}</td>
                                    <td class="py-3 px-4 text-right font-bold">
                                        @if ($att)
                                            <span class="{{ $attendanceTones[$att->status] ?? 'text-gray-700' }}">{{ $att->status_label }}</span>
                                        @elseif ($session->status === 'cancelled')
                                            <span class="text-gray-400">—</span>
                                        @elseif ($session->date && $session->date->isFuture())
                                            <span class="text-gray-400 font-semibold">Sắp tới</span>
                                        @else
                                            <span class="text-amber-600 font-semibold">Chưa điểm danh</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <!-- ────────────────────────────────────────────── -->
        <!-- SECTION 4: HỒ SƠ TỔNG HỢP (BENTO HUB VIEW) -->
        <!-- ────────────────────────────────────────────── -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- Card 1: Lớp học (lớp chính + lớp liên kết) -->
            <div class="lg:col-span-4 bg-white border border-gray-200 rounded-2xl p-5 shadow-sm flex flex-col justify-between space-y-4" data-section="classes">
                <div class="flex items-center gap-2 pb-2 border-b border-gray-100">
                    <span class="material-symbols-outlined text-blue-600 text-xl">class</span>
                    <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900">Lớp đang học</h3>
                </div>

                <div class="space-y-3 flex-grow">
                    @forelse ($classes as $class)
                        <div class="p-3.5 bg-blue-50/70 rounded-xl border border-blue-100 space-y-1 text-xs">
                            <p class="font-black text-blue-950 text-sm">
                                {{ $class->name }}
                                @if ($class->id === $student->current_class_id)
                                    <span class="ml-1 text-[10px] font-bold text-blue-700">(Lớp chính)</span>
                                @else
                                    <span class="ml-1 text-[10px] font-bold text-gray-500">(Liên kết)</span>
                                @endif
                            </p>
                            <p class="text-blue-700 flex items-center gap-1">
                                <span class="material-symbols-outlined text-[15px]">location_on</span>
                                <span>{{ $class->branch?->name ?? 'Chưa phân cơ sở' }}</span>
                            </p>
                            <p class="text-gray-700">{{ $class->schedule_text ?: 'Chưa xếp lịch' }} · {{ $class->teacher?->name ? 'GV: '.$class->teacher->name : 'Chưa có GV' }}</p>
                            @if ($class->start_date)
                                <p class="text-gray-500">Khai giảng: {{ $class->start_date->format('d/m/Y') }}</p>
                            @endif
                        </div>
                    @empty
                        <x-ui.empty-state icon="group_off" title="Chưa xếp lớp" description="Học viên chưa thuộc lớp nào đang hoạt động." />
                    @endforelse
                </div>

                @can('student.assign_class')
                    @if ($student->status === \App\Models\Student::STATUS_DROPPED)
                        <p class="text-xs text-rose-600">Học viên đã thôi học, không liên kết thêm lớp.</p>
                    @elseif ($linkableClasses->isEmpty())
                        <p class="text-xs text-gray-500">Không còn lớp phù hợp để liên kết thêm.</p>
                    @else
                        <form action="{{ route('students.link-class', $student->id) }}" method="POST" class="space-y-2" data-testid="link-class-form">
                            @csrf
                            <label for="link_class_id" class="block text-[11px] font-bold text-gray-600 uppercase">Liên kết lớp khác</label>
                            <select id="link_class_id" name="class_id" required class="w-full text-xs rounded-xl border border-gray-300 bg-slate-50 py-2 px-3">
                                <option value="">-- Chọn lớp --</option>
                                @foreach ($linkableClasses as $lc)
                                    @php $full = $lc->max_capacity > 0 && $lc->active_enrollments_count >= $lc->max_capacity; @endphp
                                    <option value="{{ $lc->id }}" @disabled($full)>
                                        {{ $lc->name }} ({{ $lc->active_enrollments_count }}/{{ $lc->max_capacity > 0 ? $lc->max_capacity : '∞' }}){{ $full ? ' — Đã đủ sĩ số' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @error('class_id')
                                <p class="text-[11px] text-rose-600" role="alert">{{ $message }}</p>
                            @enderror
                            <button type="submit" class="w-full py-2 border border-blue-600 text-blue-600 hover:bg-blue-600 hover:text-white rounded-xl text-xs font-bold transition flex items-center justify-center gap-1">
                                <span class="material-symbols-outlined text-[16px]">add_link</span>
                                <span>Liên kết lớp</span>
                            </button>
                        </form>
                    @endif
                @endcan
            </div>

            <!-- Card 2: Chuyên cần (từ điểm danh thật) -->
            <div class="lg:col-span-3 bg-white border border-gray-200 rounded-2xl p-5 shadow-sm flex flex-col items-center justify-between text-center space-y-3" data-section="attendance">
                <div class="w-full flex items-center gap-2 pb-2 border-b border-gray-100 text-left">
                    <span class="material-symbols-outlined text-emerald-600 text-xl">how_to_reg</span>
                    <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900">Chuyên cần</h3>
                </div>

                @if ($attendanceStats['rate'] === null)
                    <x-ui.empty-state icon="fact_check" title="Chưa có điểm danh" description="Chưa có buổi nào được điểm danh cho học viên." />
                @else
                    @php $pct = $attendanceStats['rate']; @endphp
                    <div class="relative w-28 h-28 flex items-center justify-center my-1">
                        <svg class="w-full h-full transform -rotate-90" aria-hidden="true">
                            <circle class="text-gray-100" cx="56" cy="56" fill="transparent" r="48" stroke="currentColor" stroke-width="8"></circle>
                            <circle class="text-emerald-500 transition-all duration-1000" cx="56" cy="56" fill="transparent" r="48" stroke="currentColor" stroke-dasharray="301.6" stroke-dashoffset="{{ 301.6 - (301.6 * $pct / 100) }}" stroke-width="8"></circle>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-2xl font-black text-gray-900 font-mono">{{ $pct }}%</span>
                        </div>
                    </div>
                @endif

                <div class="w-full space-y-1 text-xs pt-1">
                    <div class="flex justify-between text-gray-600">
                        <span>Buổi đã điểm danh:</span>
                        <span class="font-bold text-gray-900 font-mono">{{ $attendanceStats['recorded'] }}</span>
                    </div>
                    <div class="flex justify-between text-gray-600">
                        <span>Có mặt / đi muộn:</span>
                        <span class="font-bold text-emerald-700 font-mono">{{ $attendanceStats['present'] }}</span>
                    </div>
                    <div class="flex justify-between text-gray-600">
                        <span>Số buổi vắng:</span>
                        <span class="font-bold text-rose-600 font-mono">{{ $attendanceStats['absent'] }}</span>
                    </div>
                </div>

                @if ($attendances->isNotEmpty())
                    <a href="#attendance-history" class="w-full py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-semibold transition">
                        Lịch sử điểm danh
                    </a>
                @endif
            </div>

            <!-- Card 3: Thông tin Học phí (chỉ người có quyền xem học phí) -->
            @can('tuition.view')
            <div class="lg:col-span-5 bg-white border border-gray-200 rounded-2xl p-5 shadow-sm flex flex-col justify-between space-y-3" data-section="tuition">
                <div class="flex items-center justify-between pb-2 border-b border-gray-100">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-orange-600 text-xl">payments</span>
                        <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900">Thông tin học phí</h3>
                    </div>
                    @can('tuition.create')
                        <a href="{{ route('tuition.receipts.create') }}?student_id={{ $student->id }}" class="text-orange-600 font-bold text-xs hover:underline flex items-center gap-0.5">
                            <span class="material-symbols-outlined text-[15px]">add_circle</span>
                            <span>Lập phiếu thu</span>
                        </a>
                    @endcan
                </div>

                <div class="space-y-2 flex-grow max-h-[220px] overflow-y-auto pr-1 text-xs">
                    @if ($student->tuition && $student->tuition->receipts && $student->tuition->receipts->count() > 0)
                        @foreach ($student->tuition->receipts as $receipt)
                            <div class="p-3 border border-gray-200 rounded-xl flex items-center justify-between hover:border-orange-300 transition">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center">
                                        <span class="material-symbols-outlined text-base">receipt_long</span>
                                    </div>
                                    <div>
                                        <p class="font-bold text-gray-900 font-mono">#{{ $receipt->receipt_number }}</p>
                                        <p class="text-[10px] text-gray-400 font-mono">{{ $receipt->payment_date ? $receipt->payment_date->format('d/m/Y') : $receipt->created_at->format('d/m/Y') }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-black text-gray-900 font-mono">{{ number_format($receipt->amount) }}đ</p>
                                    <span class="text-[10px] font-bold uppercase {{ $receipt->status === 'approved' ? 'text-emerald-600' : 'text-amber-600' }}">
                                        {{ $receipt->status === 'approved' ? 'Đã thanh toán' : 'Chờ duyệt' }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    @else
                        <div class="p-4 border border-dashed border-gray-200 rounded-xl text-center space-y-1">
                            <span class="material-symbols-outlined text-gray-300 text-3xl">receipt_long</span>
                            <p class="text-xs text-gray-400">Chưa có phiếu thu nào được ghi nhận cho học viên.</p>
                        </div>
                    @endif
                </div>

                <div class="pt-3 border-t border-gray-100 flex items-center justify-between text-xs">
                    <div>
                        <p class="text-[10px] text-gray-400 uppercase font-bold">Tổng học phí đã nộp</p>
                        <p class="font-black text-orange-600 text-base font-mono">
                            {{ number_format($student->tuition ? $student->tuition->paid_amount : 0) }}đ
                        </p>
                    </div>
                    <a href="{{ route('tuition.students') }}" class="text-xs font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-0.5">
                        <span>Lịch sử sổ thu</span>
                        <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                    </a>
                </div>
            </div>
            @endcan

        </div>

        <!-- Chăm sóc tháng đầu: mốc ngày 3/7/14/30 (việc tự tạo cho Học vụ + checklist CRM) -->
        <section class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden" data-section="first-month-care">
            <div class="p-4 sm:p-5 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center justify-between gap-2 bg-slate-50/70">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-xl">volunteer_activism</span>
                    <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900">Chăm sóc tháng đầu</h3>
                </div>
                <div class="text-[11px] text-gray-500">
                    @if ($care['start'])
                        Bắt đầu học: <strong class="text-gray-800">{{ $care['start']->format('d/m/Y') }}</strong>
                    @else
                        Chưa có buổi học/xếp lớp để tính mốc chăm sóc
                    @endif
                    @if ($care['customer'])
                        @can('lead.view')
                            · <a href="{{ route('crm.customers.show', $care['customer']->id) }}" class="font-semibold text-primary hover:underline">Checklist bên CRM</a>
                        @endcan
                    @endif
                </div>
            </div>
            <ul class="divide-y divide-gray-100">
                @foreach ($care['items'] as $item)
                    <li class="px-4 sm:px-5 py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
                        <div class="flex items-start gap-2">
                            <span class="material-symbols-outlined text-[18px] {{ $item['done'] ? 'text-emerald-600' : 'text-gray-300' }}">{{ $item['done'] ? 'check_circle' : 'radio_button_unchecked' }}</span>
                            <div>
                                <div class="font-semibold text-gray-900">Ngày {{ $item['day'] }} — {{ $item['label'] }}</div>
                                <div class="text-[11px] text-gray-500">
                                    Hạn: {{ $item['due']?->format('d/m/Y') ?? '—' }}
                                    @if ($item['crm_done']) · Đã đánh dấu bên CRM {{ \Illuminate\Support\Carbon::parse($item['crm_done']['done_at'] ?? now())->format('d/m/Y') }} @endif
                                </div>
                            </div>
                        </div>
                        <div class="text-[11px]">
                            @if ($item['task'])
                                <span class="font-semibold text-gray-700">{{ $item['task']->assignee?->name }}</span>
                                · <span class="{{ $item['task']->status === 'completed' ? 'text-emerald-700' : 'text-amber-700' }} font-bold">{{ $item['task']->status_label }}</span>
                            @else
                                <span class="text-gray-400">Chưa tạo việc</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <!-- ────────────────────────────────────────────── -->
        <!-- SECTION 5: LỊCH SỬ ĐIỂM DANH CỦA HỌC VIÊN -->
        <!-- ────────────────────────────────────────────── -->
        @if ($attendances->isNotEmpty())
            <section id="attendance-history" class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="p-4 sm:p-5 border-b border-gray-200 flex items-center gap-2 bg-slate-50/70">
                    <span class="material-symbols-outlined text-emerald-600 text-xl">fact_check</span>
                    <h3 class="font-bold text-xs uppercase tracking-wider text-gray-900">Lịch sử điểm danh</h3>
                </div>
                <div class="overflow-x-auto max-h-[420px] overflow-y-auto">
                    <table class="w-full text-left border-collapse text-xs min-w-[560px]">
                        <thead>
                            <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                                <th class="py-3 px-4">Ngày</th>
                                <th class="py-3 px-4">Lớp</th>
                                <th class="py-3 px-4">Trạng thái</th>
                                <th class="py-3 px-4">Ghi chú</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700">
                            @foreach ($attendances as $att)
                                <tr>
                                    <td class="py-2.5 px-4 font-mono">{{ $att->session_date?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="py-2.5 px-4 font-semibold">{{ $att->classModel?->name ?? '—' }}</td>
                                    <td class="py-2.5 px-4 font-bold {{ $attendanceTones[$att->status] ?? '' }}">{{ $att->status_label }}</td>
                                    <td class="py-2.5 px-4 text-gray-500">{{ $att->note ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

    </div>
</x-app-layout>
