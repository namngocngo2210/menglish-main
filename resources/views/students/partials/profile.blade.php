{{--
    Hồ sơ học sinh — dùng chung cho "Chi tiết hồ sơ học sinh" (mockup chi-tiet-ho-so-hoc-sinh-desktop) và
    "Hồ sơ học sinh (phân quyền)" (mockup chi-tiet-ho-so-hoc-sinh-phan-quyen). Server chỉ render phần người xem có quyền:
      $canEdit (student.update)          → form sửa; không có quyền: ô khóa + "Bạn không có quyền sửa thông tin này"
      $canChangeStatus (change_status)   → menu "Đổi trạng thái"; không có quyền: nút khóa + "Quyền xem duy nhất"
      $canViewAcademic                   → lộ trình buổi học, lớp học, chuyên cần (module lớp học / điểm danh)
      $canViewContact                    → SĐT, email, địa chỉ
      $canViewTuition (tuition.view)     → thông tin học phí (dữ liệu không gửi xuống cho người khác)
    Không có trạng thái "Học thử" / cột "Học thử" (A6 Q5: hồ sơ chỉ tồn tại sau khi chốt).
--}}
@php
    $viewerRole = auth()->user()?->getRoleNames()->first();
    $viewerRoleLabel = $viewerRole ? \App\Helpers\AclHelper::roleLabel($viewerRole) : 'Người dùng';
    $weekdays = [1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5', 5 => 'Thứ 6', 6 => 'Thứ 7', 7 => 'Chủ nhật'];
    $attendanceTones = ['present' => 'text-tertiary', 'late' => 'text-warning', 'excused' => 'text-secondary', 'absent' => 'text-error'];
    $cardClass = 'rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm';
    $receiptLabels = ['approved' => ['Đã thanh toán', 'text-tertiary'], 'pending' => ['Chờ xử lý', 'text-warning'], 'draft' => ['Bản nháp', 'text-on-surface-variant'], 'rejected' => ['Bị trả về', 'text-error'], 'cancelled' => ['Đã hủy', 'text-on-surface-variant']];
    $now = now();
    $nextSessionId = $sessions->first(fn ($s) => $s->status !== 'cancelled' && $s->date && $s->date->copy()->setTimeFromTimeString($s->start_time?->format('H:i') ?? '00:00')->gte($now))?->id;
@endphp

<div class="space-y-lg">
    {{-- ─── 1. Thông tin cơ bản ─── --}}
    <section class="grid grid-cols-1 gap-lg lg:grid-cols-12">
        <div class="{{ $cardClass }} flex flex-col items-center gap-md p-lg text-center lg:col-span-4">
            <div class="relative">
                <x-ui.avatar :name="$student->name" class="!h-28 !w-28 !text-h1 ring-4 ring-primary-fixed" />
                <span class="absolute bottom-1 right-1 flex h-8 w-8 items-center justify-center rounded-full bg-primary-container text-white shadow" title="Học viên" aria-hidden="true">
                    <span class="material-symbols-outlined text-[18px]">school</span>
                </span>
            </div>
            <div class="space-y-xs">
                <h2 class="font-h2 text-h2 text-on-surface">{{ $student->name }}</h2>
                <p class="flex items-center justify-center gap-xs font-body-small text-body-small text-on-surface-variant">
                    <span class="material-symbols-outlined text-[18px] text-primary-container" aria-hidden="true">cake</span>
                    {{ $student->dob ? $student->dob->format('d/m/Y').' ('.$student->dob->age.' tuổi)' : 'Chưa cập nhật ngày sinh' }}
                </p>
                @if ($canViewContact)
                    <div data-section="contact" class="space-y-xs">
                        <p class="flex items-center justify-center gap-xs font-code text-code text-on-surface">
                            <span class="material-symbols-outlined text-[18px] text-primary-container" aria-hidden="true">call</span>{{ $student->phone ?: '—' }}
                        </p>
                        @if ($student->email)<p class="font-caption text-caption text-on-surface-variant">{{ $student->email }}</p>@endif
                        @if ($scopedView)<p class="font-caption text-caption text-on-surface-variant">Cơ sở: {{ $student->branch?->name ?? '—' }}</p>@endif
                    </div>
                @else
                    <p class="font-caption text-caption italic text-on-surface-variant">Thông tin liên hệ ẩn theo phân quyền</p>
                @endif
            </div>
            <div class="grid w-full grid-cols-2 gap-sm border-t border-surface-container pt-md">
                <div class="rounded-lg bg-surface-container-low p-sm">
                    <p class="font-label text-label uppercase text-on-surface-variant">Mã học sinh</p>
                    <p class="font-code text-code font-semibold text-primary">{{ $student->code }}</p>
                </div>
                <div class="rounded-lg bg-surface-container-low p-sm">
                    <p class="font-label text-label uppercase text-on-surface-variant">Ngày nhập học</p>
                    <p class="font-code text-code font-semibold text-on-surface">{{ $student->created_at?->format('d/m/Y') ?? '—' }}</p>
                </div>
            </div>
        </div>

        <div class="{{ $cardClass }} p-lg lg:col-span-8">
            <div class="mb-md flex flex-wrap items-center justify-between gap-sm border-b border-surface-container pb-sm">
                <h3 class="font-h3 text-h3 text-on-surface">{{ $canEdit ? 'Chỉnh sửa thông tin' : 'Thông tin học sinh' }}</h3>
                <x-ui.badge color="success" pill>Quyền: {{ $viewerRoleLabel }}</x-ui.badge>
            </div>
            @if ($canEdit)
                <form action="{{ route('students.update', $student->id) }}" method="POST" class="space-y-md" data-testid="student-edit-form">
                    @csrf
                    @method('PUT')
                    <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                        <x-ui.input name="name" label="Họ và tên" required :value="$student->name" />
                        <x-ui.input name="phone" type="tel" label="Số điện thoại" required :value="$student->phone" />
                        <x-ui.input name="email" type="email" label="Email liên hệ" :value="$student->email" placeholder="hocvien@menglish.edu.vn" />
                        <x-ui.input name="target" label="Mục tiêu học tập" :value="$student->target" placeholder="VD: IELTS 6.5, Cambridge Starters..." />
                        <x-ui.input name="parent_name" label="Họ tên phụ huynh" :value="$student->parent_name" placeholder="VD: Nguyễn Thị Hoa" />
                        <x-ui.input name="parent_phone" type="tel" label="SĐT phụ huynh (nhận kết quả Zalo)" :value="$student->parent_phone" placeholder="VD: 0987 654 321" />
                    </div>
                    <x-ui.input name="school" label="Trường học" :value="$student->school" placeholder="VD: Trường THCS Đoàn Thị Điểm" />
                    <x-ui.textarea name="address" label="Địa chỉ liên hệ" rows="2" :value="$student->address" placeholder="Nhập địa chỉ của học viên..." />
                    <x-ui.textarea name="notes" label="Ghi chú đặc biệt" rows="3" :value="$student->notes" placeholder="Nhập ghi chú về học sinh (ví dụ: dị ứng, sở thích, mục tiêu học tập...)" />
                    <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
                        <x-ui.button variant="secondary" :href="route('students.show', $student->id)">Hủy</x-ui.button>
                        <x-ui.button type="submit">Lưu thay đổi</x-ui.button>
                    </div>
                </form>
            @else
                <div class="space-y-md">
                    <x-ui.input id="ro_school" label="Trường học" :value="$student->school" disabled />
                    @if ($canViewContact)
                        <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
                            <x-ui.input id="ro_parent_name" label="Họ tên phụ huynh" :value="$student->parent_name" disabled />
                            <x-ui.input id="ro_parent_phone" label="SĐT phụ huynh" :value="$student->parent_phone" disabled />
                        </div>
                        <x-ui.textarea id="ro_address" label="Địa chỉ liên hệ" rows="2" :value="$student->address" disabled />
                    @endif
                    <x-ui.textarea id="ro_notes" label="Ghi chú đặc biệt" rows="3" :value="$student->notes" disabled />
                    <div class="grid grid-cols-2 gap-md">
                        <x-ui.input id="ro_target" label="Mục tiêu học tập" :value="$student->target" disabled />
                        <x-ui.input id="ro_current_class" label="Lớp đang học" :value="$student->currentClass?->name ?? 'Chưa xếp lớp'" disabled />
                    </div>
                    <div class="flex items-center justify-end gap-sm border-t border-surface-container pt-md">
                        <x-ui.button variant="secondary" :href="route('students.index')">Hủy</x-ui.button>
                        <div class="text-right">
                            <x-ui.button disabled>Lưu thay đổi</x-ui.button>
                            <p class="mt-xs font-caption text-caption text-error">Bạn không có quyền sửa thông tin này</p>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </section>

    {{-- ─── 2. Trạng thái vòng đời ─── --}}
    <section class="{{ $cardClass }} flex flex-col gap-md p-md sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-lg">
            <div>
                <span class="font-label text-label uppercase text-on-surface-variant">Trạng thái hiện tại</span>
                <div class="mt-xs"><x-ui.badge :color="$student->status_color" pill>{{ $student->status_label }}</x-ui.badge></div>
            </div>
            <div class="hidden h-8 w-px bg-outline-variant sm:block"></div>
            <div>
                <span class="font-label text-label uppercase text-on-surface-variant">Thời gian cập nhật</span>
                <p class="mt-xs font-code text-code text-on-surface">{{ $student->updated_at ? ($student->updated_at->isToday() ? 'Hôm nay, '.$student->updated_at->format('H:i') : $student->updated_at->format('d/m/Y H:i')) : '—' }}</p>
            </div>
        </div>
        @if ($canChangeStatus)
            <div class="flex flex-wrap items-center gap-sm">
                @if ($student->status === 'deferred')
                    {{-- Kết thúc bảo lưu: về Đang học (lớp đã khai giảng) / Chờ khai giảng, bỏ đóng băng học phí, báo Học vụ. --}}
                    <form action="{{ route('students.end-deferral', $student->id) }}" method="POST"
                          onsubmit="return confirm('Kết thúc bảo lưu cho học viên này? Học viên sẽ về Đang học (hoặc Chờ khai giảng nếu lớp chưa khai giảng) và công nợ, nhắc nợ chạy lại.')">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" size="sm" icon="play_circle">
                            Kết thúc bảo lưu
                            @if ($student->tuition?->deferred_until)
                                <span class="text-on-surface-variant">(hạn {{ $student->tuition->deferred_until->format('d/m/Y') }})</span>
                            @endif
                        </x-ui.button>
                    </form>
                @endif
                <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                    <x-ui.button variant="secondary" icon="sync" x-on:click="open = !open" ::aria-expanded="open">
                        Đổi trạng thái <span class="material-symbols-outlined text-[18px]" aria-hidden="true">expand_more</span>
                    </x-ui.button>
                    <div x-show="open" x-cloak x-transition class="absolute right-0 z-20 mt-xs w-56 overflow-hidden rounded-lg border border-outline-variant bg-surface-container-lowest py-xs shadow-level-3" role="menu">
                        @foreach (\App\Models\Student::STATUSES as $statusKey => $statusLabel)
                            @continue($statusKey === $student->status)
                            <form action="{{ route('students.status.update', $student->id) }}" method="POST"
                                  @if ($statusKey === \App\Models\Student::STATUS_DROPPED) onsubmit="return confirm('Chuyển sang Thôi học sẽ đưa học viên ra khỏi danh sách lớp đang học (vẫn giữ lịch sử). Tiếp tục?')" @endif>
                                @csrf @method('PUT')
                                <input type="hidden" name="status" value="{{ $statusKey }}">
                                <button type="submit" role="menuitem" class="flex w-full items-center gap-sm px-md py-sm text-left font-body-small text-body-small text-on-surface hover:bg-surface-container-low">
                                    <x-ui.badge :color="\App\Models\Student::STATUS_COLORS[$statusKey]" class="!px-0 !bg-transparent">{{ $statusLabel }}</x-ui.badge>
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>
            </div>
        @else
            <div class="text-right">
                <x-ui.button variant="secondary" icon="sync" disabled>Đổi trạng thái <span class="material-symbols-outlined text-[18px]" aria-hidden="true">lock</span></x-ui.button>
                <p class="mt-xs font-caption text-caption text-on-surface-variant">Quyền xem duy nhất</p>
            </div>
        @endif
    </section>

    @if ($canViewAcademic)
        {{-- ─── 3. Lộ trình học tập & Danh sách buổi học (buổi học thật + điểm danh của chính học viên) ─── --}}
        <x-ui.data-table id="roadmap" class="shadow-sm" data-section="roadmap" x-data="{ filter: 'all', showAll: false }" min-width="860px">
            <x-slot:header>
                <div class="flex items-center gap-sm">
                    <span class="material-symbols-outlined text-primary-container" aria-hidden="true">auto_stories</span>
                    <h3 class="font-h3 text-h3 text-on-surface">Lộ trình học tập &amp; Danh sách buổi học</h3>
                </div>
                <div class="flex items-center gap-sm">
                    <span class="material-symbols-outlined text-[20px] text-on-surface-variant" aria-hidden="true">filter_list</span>
                    <x-ui.select x-model="filter" aria-label="Lọc buổi học" class="py-xs"
                                 :options="['all' => 'Tất cả buổi', 'done' => 'Đã hoàn thành', 'upcoming' => 'Sắp tới', 'cancelled' => 'Đã hủy']" />
                    @unless ($scopedView)
                        <x-ui.button variant="ghost" icon="download" :href="route('students.show', ['id' => $student->id, 'export' => 'roadmap'])" title="Tải lộ trình (Excel)" aria-label="Tải lộ trình" />
                    @endunless
                </div>
            </x-slot:header>

            @if ($sessions->isEmpty())
                <x-ui.empty-state icon="event_busy" title="Chưa có buổi học nào"
                    :description="$classes->isEmpty() ? 'Học viên chưa được xếp lớp nên chưa có lộ trình buổi học.' : 'Lớp của học viên chưa được sinh lịch buổi học.'" />
            @else
                    <table class="font-body-small text-body-small">
                        <thead>
                            <tr>
                                <th>Ngày học</th>
                                <th>Thời gian</th>
                                <th>Nội dung bài học</th>
                                <th>Giáo viên</th>
                                <th>Trạng thái</th>
                                <th class="text-right">Điểm danh</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sessions as $idx => $session)
                                @php
                                    $att = $attendanceBySession->get($session->id);
                                    $lesson = $lessons[$session->id] ?? null;
                                    $isPast = $session->date && $session->date->copy()->setTimeFromTimeString($session->start_time?->format('H:i') ?? '00:00')->lt($now);
                                    $state = $session->status === 'cancelled' ? 'cancelled' : (($session->status === 'completed' || $isPast) ? 'done' : 'upcoming');
                                    $teacherName = $session->teacher?->name ?? $session->classModel?->teacher?->name;
                                @endphp
                                <tr x-show="(showAll || {{ $idx }} < 10) && (filter === 'all' || filter === '{{ $state }}')">
                                    <td class="whitespace-nowrap font-semibold">{{ $session->date ? $weekdays[$session->date->isoWeekday()].', '.$session->date->format('d/m/Y') : '—' }}</td>
                                    <td class="whitespace-nowrap font-code">{{ $session->start_time?->format('H:i') }}@if ($session->end_time) - {{ $session->end_time->format('H:i') }}@endif</td>
                                    <td>
                                        @if ($lesson && ($lesson['unit'] || $lesson['title']))
                                            <span class="block font-semibold">{{ $lesson['unit'] ?? 'Buổi '.$lesson['no'] }}</span>
                                            @if ($lesson['title'])<span class="block text-on-surface-variant">{{ $lesson['title'] }}</span>@endif
                                        @else
                                            <span class="block font-semibold">{{ $lesson ? 'Buổi '.$lesson['no'] : ($session->type === \App\Models\ClassSession::TYPE_MAKEUP ? 'Buổi học bù' : 'Buổi học') }}</span>
                                            <span class="block text-on-surface-variant">{{ $lesson ? 'Chưa gắn nội dung giáo trình' : '' }}</span>
                                        @endif
                                        <span class="block font-caption text-caption text-on-surface-variant">
                                            {{ $session->classModel?->name ?? '—' }}@if ($session->room) · {{ str_starts_with(mb_strtolower($session->room), 'phòng') ? $session->room : 'Phòng '.$session->room }}@endif
                                            @if ($session->type === \App\Models\ClassSession::TYPE_MAKEUP) · <span class="font-semibold text-warning">Học bù</span>@elseif ($session->type === \App\Models\ClassSession::TYPE_SUPPORT) · <span class="font-semibold text-secondary">Phụ đạo</span>@endif
                                        </span>
                                    </td>
                                    <td>
                                        @if ($teacherName)
                                            <span class="flex items-center gap-sm whitespace-nowrap"><x-ui.avatar :name="$teacherName" size="sm" />{{ $teacherName }}</span>
                                        @else
                                            <span class="text-on-surface-variant">Chưa gán GV</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap">
                                        @if ($state === 'cancelled')
                                            <span class="inline-flex items-center gap-xs text-on-surface-variant"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">event_busy</span>Đã hủy</span>
                                        @elseif ($state === 'done')
                                            <span class="inline-flex items-center gap-xs font-semibold text-tertiary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">check_circle</span>Đã hoàn thành</span>
                                        @elseif ($session->id === $nextSessionId)
                                            <span class="inline-flex items-center gap-xs font-semibold text-primary"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">schedule</span>Sắp diễn ra</span>
                                        @else
                                            <span class="inline-flex items-center gap-xs text-on-surface-variant"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">radio_button_unchecked</span>Chưa bắt đầu</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap text-right font-semibold">
                                        @if ($att)
                                            <span class="{{ $attendanceTones[$att->status] ?? '' }}">{{ $att->status_label }}</span>
                                        @elseif ($state === 'cancelled')
                                            <span class="text-on-surface-variant">—</span>
                                        @elseif ($state === 'upcoming')
                                            <span class="text-on-surface-variant">Sắp tới</span>
                                        @else
                                            <span class="text-warning">Chưa điểm danh</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @if ($sessions->count() > 10)
                    <x-slot:footer>
                    <div class="p-sm text-center">
                        <button type="button" class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-primary hover:underline" x-on:click="showAll = !showAll">
                            <span x-text="showAll ? 'Thu gọn' : 'Xem toàn bộ {{ $sessions->count() }} buổi học'">Xem toàn bộ {{ $sessions->count() }} buổi học</span>
                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true" x-text="showAll ? 'expand_less' : 'expand_more'">expand_more</span>
                        </button>
                    </div>
                    </x-slot:footer>
                @endif
            @endif
        </x-ui.data-table>
    @endif

    {{-- ─── 4. Hồ sơ tổng hợp: Lớp học · Chuyên cần · Học phí ─── --}}
    <div class="grid grid-cols-1 gap-lg lg:grid-cols-12">
        @if ($canViewAcademic)
            <div class="{{ $cardClass }} flex flex-col gap-md p-md lg:col-span-4" data-section="classes">
                <div class="flex items-center gap-sm border-b border-surface-container pb-sm">
                    <span class="material-symbols-outlined text-secondary" aria-hidden="true">class</span>
                    <h3 class="font-h3 text-h3 text-on-surface">Lớp học hiện tại</h3>
                </div>
                <div class="flex-1 space-y-sm">
                    @forelse ($classes as $class)
                        <div class="space-y-sm rounded-lg border border-secondary-fixed bg-secondary-fixed/30 p-md">
                            <div>
                                <p class="font-body-medium text-body-medium font-semibold text-on-surface">
                                    {{ $class->name }}
                                    <span class="font-caption text-caption {{ $class->id === $student->current_class_id ? 'text-secondary' : 'text-on-surface-variant' }}">({{ $class->id === $student->current_class_id ? 'Lớp chính' : 'Liên kết' }})</span>
                                </p>
                                <p class="flex items-center gap-xs font-caption text-caption text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">location_on</span>{{ $class->branch?->name ?? 'Chưa phân cơ sở' }}
                                </p>
                            </div>
                            <div class="grid grid-cols-2 gap-sm font-caption text-caption">
                                <div>
                                    <p class="font-label text-label uppercase text-on-surface-variant">Lịch học</p>
                                    <p class="text-on-surface">{{ $class->schedule_text ?: 'Chưa xếp lịch' }}</p>
                                    <p class="text-on-surface-variant">{{ $class->teacher?->name ? 'GV: '.$class->teacher->name : 'Chưa có GV' }}</p>
                                </div>
                                <div>
                                    <p class="font-label text-label uppercase text-on-surface-variant">Thời gian</p>
                                    <p class="font-code text-on-surface">{{ $class->start_date?->format('d/m/y') ?? '—' }} - {{ $class->end_date?->format('d/m/y') ?? '—' }}</p>
                                    @if ($class->end_date)
                                        <p class="text-on-surface-variant">
                                            @if ($class->end_date->isPast()) Đã kết thúc
                                            @elseif (($months = (int) now()->diffInMonths($class->end_date)) >= 1) Còn {{ $months }} tháng
                                            @else Còn {{ (int) now()->diffInDays($class->end_date) }} ngày
                                            @endif
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <x-ui.empty-state icon="group_off" title="Chưa xếp lớp" description="Học viên chưa thuộc lớp nào đang hoạt động." />
                    @endforelse
                </div>

                <x-ui.button variant="secondary" class="w-full" href="#roadmap">Chi tiết lộ trình</x-ui.button>

                @if (! $scopedView && auth()->user()->can('student.assign_class'))
                    @if ($student->status === \App\Models\Student::STATUS_DROPPED)
                        <p class="font-caption text-caption text-error">Học viên đã thôi học, không liên kết thêm lớp.</p>
                    @elseif ($linkableClasses->isEmpty())
                        <p class="font-caption text-caption text-on-surface-variant">Không còn lớp phù hợp để liên kết thêm.</p>
                    @else
                        <form action="{{ route('students.link-class', $student->id) }}" method="POST" class="space-y-sm border-t border-surface-container pt-md" data-testid="link-class-form">
                            @csrf
                            <x-ui.select label="Liên kết lớp khác" name="class_id" id="link_class_id" required placeholder="-- Chọn lớp --">
                                    @foreach ($linkableClasses as $lc)
                                        @php $full = $lc->max_capacity > 0 && $lc->active_enrollments_count >= $lc->max_capacity; @endphp
                                        <option value="{{ $lc->id }}" @disabled($full)>
                                            {{ $lc->name }} ({{ $lc->active_enrollments_count }}/{{ $lc->max_capacity > 0 ? $lc->max_capacity : '∞' }}){{ $full ? ' — Đã đủ sĩ số' : '' }}
                                        </option>
                                    @endforeach
                            </x-ui.select>
                            <x-ui.button type="submit" variant="secondary" icon="add_link" class="w-full">Liên kết lớp</x-ui.button>
                        </form>
                    @endif
                @endif
            </div>

            <div class="{{ $cardClass }} flex flex-col items-center gap-md p-md text-center lg:col-span-3" data-section="academic">
                <div class="flex w-full items-center gap-sm border-b border-surface-container pb-sm text-left">
                    <span class="material-symbols-outlined text-tertiary" aria-hidden="true">how_to_reg</span>
                    <h3 class="font-h3 text-h3 text-on-surface">Chuyên cần</h3>
                </div>
                @if ($attendanceStats['rate'] === null)
                    <x-ui.empty-state icon="fact_check" title="Chưa có điểm danh" description="Chưa có buổi nào được điểm danh cho học viên." />
                @else
                    @php $pct = $attendanceStats['rate']; @endphp
                    <div class="relative flex h-32 w-32 items-center justify-center">
                        <svg class="h-full w-full -rotate-90" viewBox="0 0 128 128" aria-hidden="true">
                            <circle class="text-surface-container-high" cx="64" cy="64" fill="transparent" r="58" stroke="currentColor" stroke-width="8"></circle>
                            <circle class="text-tertiary" cx="64" cy="64" fill="transparent" r="58" stroke="currentColor" stroke-dasharray="364.4" stroke-dashoffset="{{ 364.4 - (364.4 * $pct / 100) }}" stroke-width="10" stroke-linecap="round"></circle>
                        </svg>
                        <span class="absolute font-h2 text-h2 text-on-surface">{{ $pct }}%</span>
                    </div>
                @endif
                <div class="w-full space-y-xs font-body-small text-body-small">
                    <div class="flex justify-between"><span class="text-on-surface-variant">Tổng số buổi:</span><span class="font-code font-semibold">{{ $attendanceStats['scheduled'] }}</span></div>
                    <div class="flex justify-between"><span class="text-on-surface-variant">Buổi đã điểm danh:</span><span class="font-code font-semibold">{{ $attendanceStats['recorded'] }}</span></div>
                    <div class="flex justify-between"><span class="text-on-surface-variant">Có mặt / đi muộn:</span><span class="font-code font-semibold text-tertiary">{{ $attendanceStats['present'] }}</span></div>
                    <div class="flex justify-between"><span class="text-on-surface-variant">Số buổi vắng:</span><span class="font-code font-semibold text-error">{{ str_pad((string) $attendanceStats['absent'], 2, '0', STR_PAD_LEFT) }}</span></div>
                </div>
                @if ($attendances->isNotEmpty())
                    <x-ui.button variant="secondary" class="w-full" href="#attendance-history">Lịch sử điểm danh</x-ui.button>
                @endif
            </div>
        @else
            <div class="{{ $cardClass }} flex items-center gap-md p-md lg:col-span-7">
                <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">lock</span>
                <p class="font-body-small text-body-small text-on-surface-variant">Lớp học, lộ trình và chuyên cần ẩn theo phân quyền (cần quyền xem điểm danh học viên).</p>
            </div>
        @endif

        @if ($canViewTuition)
            <div class="{{ $cardClass }} flex flex-col gap-md p-md lg:col-span-5" data-section="tuition">
                <div class="flex items-center justify-between border-b border-surface-container pb-sm">
                    <div class="flex items-center gap-sm">
                        <span class="material-symbols-outlined text-primary-container" aria-hidden="true">payments</span>
                        <h3 class="font-h3 text-h3 text-on-surface">Thông tin học phí</h3>
                    </div>
                    @can('tuition.create')
                        <a href="{{ route('tuition.receipts.create', ['student_id' => $student->id]) }}" hx-get="{{ route('tuition.receipts.create', ['student_id' => $student->id]) }}" hx-target="#remote-modal-body" hx-swap="innerHTML" data-modal-size="4xl"
                           class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-primary hover:underline">
                            Thêm phiếu thu <span class="material-symbols-outlined text-[18px]" aria-hidden="true">add_circle</span>
                        </a>
                    @endcan
                </div>
                @if ($student->tuition)
                    <div class="grid grid-cols-3 gap-sm rounded-lg bg-surface-container-low p-sm text-center font-caption text-caption">
                        <div><p class="text-on-surface-variant">Tổng học phí</p><p class="font-code font-semibold text-on-surface">{{ number_format($student->tuition->final_amount) }}đ</p></div>
                        <div><p class="text-on-surface-variant">Đã thanh toán</p><p class="font-code font-semibold text-tertiary">{{ number_format($student->tuition->paid_amount) }}đ</p></div>
                        <div><p class="text-on-surface-variant">Công nợ</p><p class="font-code font-semibold text-error">{{ number_format($student->tuition->debt_amount) }}đ</p></div>
                    </div>
                    <div class="max-h-[220px] flex-1 space-y-sm overflow-y-auto pr-xs">
                        @forelse ($student->tuition->receipts ?? [] as $receipt)
                            @php [$rLabel, $rTone] = $receiptLabels[$receipt->status] ?? [$receipt->status_label, 'text-on-surface-variant']; @endphp
                            <div class="flex items-center justify-between rounded-lg border border-outline-variant p-sm">
                                <div class="flex items-center gap-sm">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-fixed text-primary"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">receipt_long</span></span>
                                    <div>
                                        <p class="font-code text-code font-semibold">#{{ $receipt->receipt_number }}</p>
                                        <p class="font-caption text-caption text-on-surface-variant">{{ ($receipt->payment_date ?? $receipt->created_at)?->format('d/m/Y') }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-code text-code font-semibold">{{ number_format($receipt->amount) }}đ</p>
                                    <span class="font-caption text-caption font-semibold {{ $rTone }}">{{ $rLabel }}</span>
                                </div>
                            </div>
                        @empty
                            <p class="rounded-lg border border-dashed border-outline-variant p-md text-center font-body-small text-body-small text-on-surface-variant">Chưa có phiếu thu nào được ghi nhận cho học viên.</p>
                        @endforelse
                    </div>
                    <div class="flex items-center justify-between border-t border-surface-container pt-sm">
                        <div>
                            <p class="font-label text-label uppercase text-on-surface-variant">Tổng học phí đã nộp</p>
                            <p class="font-h3 text-h3 text-primary">{{ number_format($student->tuition->paid_amount) }}đ</p>
                        </div>
                        <x-ui.button variant="ghost" icon="more_horiz" :href="route('tuition.students', ['search' => $student->code])" title="Lịch sử sổ thu" aria-label="Lịch sử sổ thu" />
                    </div>
                @else
                    <x-ui.empty-state icon="receipt_long" title="Chưa có sổ học phí" description="Học viên chưa được lập sổ học phí." />
                @endif
            </div>
        @endif
    </div>

    @if ($canViewAcademic && $care)
        {{-- Chăm sóc tháng đầu: 3 mốc gate hoa hồng A6 — Buổi 1, Buổi 4–5, Đủ 30 ngày (việc tự tạo cho Học vụ + checklist CRM) --}}
        <section class="{{ $cardClass }} overflow-hidden" data-section="first-month-care">
            <div class="flex flex-col justify-between gap-sm border-b border-surface-container p-md sm:flex-row sm:items-center">
                <div class="flex items-center gap-sm">
                    <span class="material-symbols-outlined text-primary" aria-hidden="true">volunteer_activism</span>
                    <h3 class="font-h3 text-h3 text-on-surface">Chăm sóc tháng đầu</h3>
                    <x-ui.badge :color="$care['completed'] >= 3 ? 'success' : 'warning'" pill>{{ $care['completed'] }}/3 mốc</x-ui.badge>
                </div>
                <div class="font-caption text-caption text-on-surface-variant">
                    @if ($care['closing'])
                        Ngày chốt: <strong class="text-on-surface">{{ $care['closing']->format('d/m/Y') }}</strong> ·
                    @endif
                    @if ($care['start'])
                        Bắt đầu học: <strong class="text-on-surface">{{ $care['start']->format('d/m/Y') }}</strong>
                    @else
                        Chưa có buổi học/xếp lớp
                    @endif
                    @if ($care['customer'])
                        @can('lead.view')
                            · <a href="{{ route('crm.customers.show', $care['customer']->id) }}" class="font-semibold text-primary hover:underline">Checklist bên CRM</a>
                        @endcan
                    @endif
                </div>
            </div>
            <ul class="divide-y divide-surface-container">
                @foreach ($care['items'] as $item)
                    <li class="flex flex-col justify-between gap-sm px-md py-sm font-body-small text-body-small sm:flex-row sm:items-center">
                        <div class="flex items-start gap-sm">
                            <span class="material-symbols-outlined text-[18px] {{ $item['done'] ? 'text-tertiary' : 'text-outline-variant' }}" aria-hidden="true">{{ $item['done'] ? 'check_circle' : 'radio_button_unchecked' }}</span>
                            <div>
                                <div class="font-semibold text-on-surface">{{ $item['label'] }}</div>
                                <div class="font-caption text-caption text-on-surface-variant">
                                    @if ($item['due'])
                                        Hạn: {{ $item['due']->format('d/m/Y') }}
                                    @else
                                        {{ $item['milestone'] === \App\Services\FirstMonthCareService::MILESTONE_DAY_30 ? 'Chưa có ngày chốt' : 'Chờ học viên có mặt đủ '.($item['milestone'] === \App\Services\FirstMonthCareService::MILESTONE_SESSION_1 ? '1 buổi' : '4 buổi') }}
                                    @endif
                                    @if ($item['crm_done']) · Đã đánh dấu bên CRM {{ \Illuminate\Support\Carbon::parse($item['crm_done']['done_at'] ?? now())->format('d/m/Y') }} @endif
                                </div>
                            </div>
                        </div>
                        <div class="font-caption text-caption">
                            @if ($item['task'])
                                <span class="font-semibold text-on-surface">{{ $item['task']->assignee?->name }}</span>
                                · <span class="font-semibold {{ $item['task']->status === 'completed' ? 'text-tertiary' : 'text-warning' }}">{{ $item['task']->status_label }}</span>
                            @else
                                <span class="text-on-surface-variant">Chưa tạo việc</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($canViewAcademic && $attendances->isNotEmpty())
        <x-ui.data-table id="attendance-history" class="shadow-sm" min-width="560px">
            <x-slot:header>
                <h3 class="flex items-center gap-sm font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-tertiary" aria-hidden="true">fact_check</span>
                    Lịch sử điểm danh
                </h3>
            </x-slot:header>
            <div class="custom-scrollbar max-h-[420px] overflow-y-auto">
                <table class="font-body-small text-body-small">
                    <thead>
                        <tr><th>Ngày</th><th>Lớp</th><th>Trạng thái</th><th>Ghi chú</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($attendances as $att)
                            <tr>
                                <td class="font-code">{{ $att->session_date?->format('d/m/Y') ?? '—' }}</td>
                                <td class="font-semibold">{{ $att->classModel?->name ?? '—' }}</td>
                                <td class="font-semibold {{ $attendanceTones[$att->status] ?? '' }}">{{ $att->status_label }}</td>
                                <td class="text-on-surface-variant">{{ $att->note ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.data-table>
    @endif
</div>
