{{-- Mockup: ui-full-tinh-nang-menglish/epic-7/cau-hinh-don-gia-giao-vien --}}
<x-app-layout>
    @php
        $teacherList = $teachers->map(fn ($t) => [
            'id' => $t->id,
            'search' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($t->name.' '.$t->employee_code.' '.$t->email)),
        ])->values();
        $selectedCurrent = $selectedTeacher ? $currentRates->get($selectedTeacher->id) : null;
        $unitSuffix = ['session' => 'VNĐ / buổi', 'hour' => 'VNĐ / giờ'];
    @endphp

    <x-ui.page-header title="Cấu hình đơn giá giáo viên"
                      description="Quản lý và cập nhật định mức lương theo buổi / giờ cho từng giáo viên. Đổi giá = thêm phiên bản mới có ngày hiệu lực, buổi dạy cũ vẫn tính theo giá cũ.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="percent" :href="route('payroll.config.commission-tiers')">Cấu hình hoa hồng</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($errors->any())
        <x-ui.alert type="error" class="mb-md">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 items-start gap-lg lg:grid-cols-12">
        {{-- 1. Chọn giáo viên + đơn giá hiện hành --}}
        <div class="space-y-md lg:col-span-4">
            <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm"
                     x-data="{ q: '', list: @js($teacherList),
                               match(id) { const q = this.q.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd'); return ! q || this.list.find(t => t.id === id)?.search.includes(q); } }">
                <h3 class="mb-sm font-h3 text-h3 text-on-surface">1. Chọn giáo viên</h3>
                <div class="relative mb-sm">
                    <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">search</span>
                    <input type="search" x-model="q" placeholder="Tìm tên hoặc mã nhân viên..." aria-label="Tìm giáo viên"
                           class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-10 pr-md font-body-base text-body-base placeholder:text-on-surface-variant/60 focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
                </div>
                <ul class="custom-scrollbar max-h-72 space-y-xs overflow-y-auto">
                    @forelse ($teachers as $teacher)
                        @php $active = $selectedTeacher?->id === $teacher->id; @endphp
                        <li x-show="match({{ $teacher->id }})">
                            <a href="{{ route('payroll.config.teacher-rates', ['teacher_id' => $teacher->id]) }}"
                               class="flex items-center gap-sm rounded-lg border p-sm transition-colors {{ $active ? 'border-primary-container bg-primary-fixed/40' : 'border-transparent hover:bg-surface-container-low' }}">
                                <x-ui.avatar :name="$teacher->name" size="sm" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-body-medium text-body-medium text-on-surface">{{ $teacher->name }}</span>
                                    <span class="block font-caption text-caption text-on-surface-variant">Mã NV: {{ $teacher->employee_code ?: '—' }}</span>
                                </span>
                                <x-ui.badge :color="$teacher->is_active ? 'success' : 'neutral'" pill>{{ $teacher->is_active ? 'Đang giảng dạy' : 'Ngừng hoạt động' }}</x-ui.badge>
                            </a>
                        </li>
                    @empty
                        <li><x-ui.empty-state icon="person_off" title="Chưa có nhân sự giảng dạy" /></li>
                    @endforelse
                </ul>
            </section>

            @if ($selectedTeacher)
                <section class="rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm">
                    <h3 class="mb-sm font-h3 text-h3 text-on-surface">Đơn giá hiện hành</h3>
                    <dl class="space-y-sm">
                        <div>
                            <dt class="font-body-small text-body-small text-on-surface-variant">Loại giáo viên</dt>
                            <dd class="mt-xs inline-flex items-center gap-xs font-body-medium text-body-medium text-on-surface">
                                <span class="material-symbols-outlined text-[18px] text-primary-container" aria-hidden="true">badge</span>
                                {{ \App\Models\TeacherHourlyRate::TEACHER_TYPES[$selectedType] ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-body-small text-body-small text-on-surface-variant">Mức lương đang áp dụng</dt>
                            <dd class="font-h2 text-h2 text-primary">
                                @if ($selectedCurrent)
                                    {{ number_format((float) $selectedCurrent->hourly_rate, 0, ',', '.') }} {{ $unitSuffix[$selectedCurrent->rate_unit] ?? 'VNĐ / giờ' }}
                                @elseif ((float) $selectedTeacher->hourly_rate > 0)
                                    {{ number_format((float) $selectedTeacher->hourly_rate, 0, ',', '.') }} VNĐ / giờ
                                    <span class="block font-caption text-caption text-on-surface-variant">theo hồ sơ nhân sự</span>
                                @else
                                    <span class="font-body-medium text-body-medium text-on-surface-variant">Chưa có đơn giá riêng (mặc định {{ number_format(\App\Models\TeacherTimesheet::DEFAULT_HOURLY_RATE, 0, ',', '.') }} VNĐ / giờ)</span>
                                @endif
                            </dd>
                            @if ($selectedCurrent)
                                <p class="font-body-small text-body-small text-on-surface-variant">Hiệu lực từ: {{ $selectedCurrent->effective_from->format('d/m/Y') }}</p>
                            @endif
                        </div>
                    </dl>
                </section>
            @endif
        </div>

        <div class="space-y-lg lg:col-span-8">
            {{-- 2. Cập nhật đơn giá mới --}}
            <form action="{{ route('payroll.config.teacher-rates.personal.store') }}" method="POST"
                  class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm"
                  x-data="{ unit: @js(old('rate_unit', 'session')), type: @js(old('teacher_type', $selectedType ?? 'parttime')) }">
                @csrf
                <h3 class="font-h3 text-h3 text-on-surface">2. Cập nhật đơn giá mới{{ $selectedTeacher ? ' — '.$selectedTeacher->name : '' }}</h3>

                @if ($selectedTeacher)
                    <input type="hidden" name="user_id" value="{{ $selectedTeacher->id }}">
                @else
                    <x-ui.select name="user_id" label="Giáo viên" required placeholder="-- Chọn giáo viên ở khung bên trái hoặc tại đây --"
                                 :value="old('user_id')"
                                 :options="$teachers->mapWithKeys(fn ($t) => [$t->id => $t->name.($t->employee_code ? ' — '.$t->employee_code : '')])" />
                @endif

                <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <x-ui.select name="teacher_type" label="Loại giáo viên" required x-model="type" :options="\App\Models\TeacherHourlyRate::TEACHER_TYPES" />
                    <x-ui.date name="effective_from" label="Ngày hiệu lực từ" required :value="old('effective_from', now()->toDateString())"
                               hint="Áp dụng cho các ca dạy từ ngày này tới khi có đơn giá mới hơn." />
                    <x-ui.select name="rate_unit" label="Đơn vị tính" required x-model="unit"
                                 :options="['session' => 'Theo buổi dạy (VNĐ / buổi)', 'hour' => 'Theo giờ (VNĐ / giờ)']" />
                    <x-ui.field label="Mức đơn giá mới" name="hourly_rate" for="f_hourly_rate" required
                                hint="* Đơn vị tính theo loại giáo viên: Part-time tính theo buổi.">
                        <div class="flex items-center gap-sm">
                            <input type="number" id="f_hourly_rate" name="hourly_rate" required min="1000" step="1000" value="{{ old('hourly_rate') }}" placeholder="Nhập số tiền..."
                                   class="w-full rounded-lg border {{ $errors->has('hourly_rate') ? 'border-error' : 'border-outline-variant' }} bg-surface-container-lowest px-md py-sm text-right font-mono text-body-base focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
                            <span class="whitespace-nowrap font-body-medium text-body-medium text-on-surface-variant" x-text="unit === 'session' ? 'VNĐ / buổi' : 'VNĐ / giờ'">VNĐ / buổi</span>
                        </div>
                    </x-ui.field>
                </div>
                <x-ui.textarea name="note" label="Ghi chú / Lý do thay đổi" rows="2" placeholder="Nhập ghi chú nếu có..." />

                <p x-show="type === 'fulltime'" x-cloak class="rounded-lg bg-amber-50 px-md py-sm font-body-small text-body-small text-amber-900">
                    GV Full-time hưởng lương cơ bản — đơn giá buổi chỉ dùng để đối soát, không cộng vào lương.
                </p>
                <p x-show="type === 'foreign'" x-cloak class="rounded-lg bg-amber-50 px-md py-sm font-body-small text-body-small text-amber-900">
                    Lương buổi có GVNN đang chờ BA chốt cách tính — Kế toán nhập tay trên phiếu lương.
                </p>

                <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
                    <x-ui.button variant="secondary" :href="route('payroll.config.teacher-rates', array_filter(['teacher_id' => $selectedTeacher?->id]))">Hủy bỏ</x-ui.button>
                    <x-ui.button type="submit" icon="save">Cập nhật đơn giá mới</x-ui.button>
                </div>
            </form>

            {{-- 3. Lịch sử thay đổi đơn giá --}}
            <x-ui.data-table min-width="760px">
                <x-slot:header>
                    <h3 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                        <span class="material-symbols-outlined text-primary-container" aria-hidden="true">history</span>
                        Lịch sử thay đổi đơn giá{{ $selectedTeacher ? ': '.$selectedTeacher->name : '' }}
                    </h3>
                    @if ($selectedTeacher)
                        <x-ui.button variant="ghost" size="sm" icon="filter_alt_off" :href="route('payroll.config.teacher-rates')">Xem tất cả</x-ui.button>
                    @endif
                </x-slot:header>
                <table>
                    <thead>
                        <tr>
                            @unless ($selectedTeacher)<th>Giáo viên</th>@endunless
                            <th>Loại GV</th>
                            <th class="text-right">Đơn giá</th>
                            <th>Đơn vị tính</th>
                            <th>Hiệu lực từ</th>
                            <th>Đến ngày</th>
                            <th>Trạng thái</th>
                            <th>Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($history as $row)
                            @php
                                $end = $endDates[$row->id] ?? null;
                                [$stateLabel, $stateColor] = $row->effective_from->isFuture()
                                    ? ['Chưa hiệu lực', 'info']
                                    : ($end && $end->lt(today()) ? ['Đã hết hạn', 'neutral'] : ['Đang áp dụng', 'success']);
                            @endphp
                            <tr>
                                @unless ($selectedTeacher)
                                    <td class="font-semibold"><a href="{{ route('payroll.config.teacher-rates', ['teacher_id' => $row->user_id]) }}" class="hover:text-primary">{{ $row->user?->name ?? '—' }}</a></td>
                                @endunless
                                <td>{{ $row->teacher_type_label }}</td>
                                <td><x-ui.money :value="$row->hourly_rate" suffix="" /></td>
                                <td>{{ $unitSuffix[$row->rate_unit] ?? 'VNĐ / giờ' }} <span class="sr-only">{{ $row->unit_label }}</span></td>
                                <td class="font-code text-code">{{ $row->effective_from->format('d/m/Y') }}</td>
                                <td class="font-code text-code">{{ $end ? $end->format('d/m/Y') : 'Hiện tại' }}</td>
                                <td><x-ui.badge :color="$stateColor">{{ $stateLabel }}</x-ui.badge></td>
                                <td>
                                    {{ $row->note ?? '—' }}
                                    <span class="block font-caption text-caption text-on-surface-variant">{{ $row->creator?->name ?? 'Hệ thống' }} · {{ $row->created_at?->format('d/m/Y H:i') }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8"><x-ui.empty-state icon="history" title="Chưa có lịch sử đơn giá" description="Chọn giáo viên và cập nhật đơn giá mới ở khung trên." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                <x-slot:footer><x-ui.pagination :paginator="$history" /></x-slot:footer>
            </x-ui.data-table>

            @unless ($selectedTeacher)
                {{-- Tổng quan đơn giá đang hiệu lực của mọi GV --}}
                <x-ui.data-table min-width="560px">
                    <x-slot:header>
                        <h3 class="font-h3 text-h3 text-on-surface">Đơn giá đang hiệu lực ({{ now()->format('d/m/Y') }})</h3>
                    </x-slot:header>
                    <table>
                        <thead><tr><th>Giáo viên</th><th class="text-right">Đơn giá</th><th>Hiệu lực từ</th><th class="text-right">Lịch sử</th></tr></thead>
                        <tbody>
                            @foreach ($teachers as $teacher)
                                @php $current = $currentRates->get($teacher->id); @endphp
                                <tr>
                                    <td>
                                        <p class="font-semibold">{{ $teacher->name }}</p>
                                        <p class="font-caption text-caption text-on-surface-variant">{{ $teacher->employee_code ?: '—' }}</p>
                                    </td>
                                    <td>
                                        @if ($current)
                                            <x-ui.money :value="$current->hourly_rate" :suffix="$current->unit_label" />
                                        @elseif ((float) $teacher->hourly_rate > 0)
                                            <x-ui.money :value="$teacher->hourly_rate" suffix="đ/giờ" />
                                            <span class="block text-right font-caption text-caption text-on-surface-variant">theo hồ sơ nhân sự</span>
                                        @else
                                            <span class="block text-right font-caption text-caption text-on-surface-variant">Mặc định</span>
                                        @endif
                                    </td>
                                    <td class="font-code text-code">{{ $current?->effective_from?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="text-right"><x-ui.button variant="ghost" size="sm" icon="history" :href="route('payroll.config.teacher-rates', ['teacher_id' => $teacher->id])">Xem</x-ui.button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.data-table>
            @endunless

            {{-- Khung đơn giá theo cấp bậc (tham khảo khi đặt giá cho GV) --}}
            <details class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                <summary class="cursor-pointer px-lg py-md font-body-medium text-body-medium text-on-surface">
                    Khung đơn giá tham khảo theo cấp bậc ({{ $rates->count() }} bậc)
                </summary>
                <div class="space-y-md border-t border-surface-container p-lg">
                    <x-ui.data-table>
                        <table>
                            <thead><tr><th>Cấp bậc</th><th>Yêu cầu</th><th class="text-right">Lớp Giao tiếp</th><th class="text-right">Lớp IELTS / Cambridge</th></tr></thead>
                            <tbody>
                                @forelse ($rates as $r)
                                    <tr>
                                        <td class="font-semibold">{{ $r->rank_title }}</td>
                                        <td>{{ $r->criteria }}</td>
                                        <td><x-ui.money :value="$r->communication_rate" suffix="đ" /></td>
                                        <td><x-ui.money :value="$r->ielts_rate" suffix="đ" /></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4"><x-ui.empty-state title="Chưa có khung đơn giá" /></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </x-ui.data-table>

                    <form action="{{ route('payroll.config.teacher-rates.store') }}" method="POST" class="grid grid-cols-1 gap-md sm:grid-cols-2">
                        @csrf
                        <x-ui.input name="rank_title" label="Cấp bậc" required placeholder="VD: Senior IELTS Trainer" />
                        <x-ui.input name="criteria" label="Yêu cầu chứng chỉ & kinh nghiệm" placeholder="IELTS 8.0+, 3 năm KN" />
                        <x-ui.input type="number" name="communication_rate" label="Lớp Giao tiếp (VNĐ/giờ)" required step="10000" />
                        <x-ui.input type="number" name="ielts_rate" label="Lớp IELTS / Cambridge (VNĐ/giờ)" required step="10000" />
                        <div class="flex justify-end sm:col-span-2">
                            <x-ui.button type="submit" variant="secondary" icon="add">Thêm cấp bậc tham khảo</x-ui.button>
                        </div>
                    </form>
                </div>
            </details>
        </div>
    </div>
</x-app-layout>
