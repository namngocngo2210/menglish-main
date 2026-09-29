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
            <x-ui.button icon="price_change" x-on:click="$dispatch('open-modal', 'new-rate')">Cập nhật đơn giá</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($errors->any())
        <x-ui.alert type="error" class="mb-md">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="space-y-lg">
        {{-- Đơn giá đang hiệu lực của mọi GV — bấm dòng → modal chi tiết GV (?teacher_id=; đóng modal thì bỏ query). --}}
        <div x-data="{ q: '', list: @js($teacherList),
                       match(id) { const q = this.q.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd'); return ! q || this.list.find(t => t.id === id)?.search.includes(q); } }">
            <x-ui.data-table min-width="640px">
                <x-slot:header>
                    <h3 class="font-h3 text-h3 text-on-surface">Đơn giá đang hiệu lực ({{ now()->format('d/m/Y') }})</h3>
                    <div class="w-full sm:w-72">
                        <x-ui.input type="search" icon="search" x-model="q" placeholder="Tìm tên hoặc mã nhân viên..." aria-label="Tìm giáo viên" />
                    </div>
                </x-slot:header>
                <table>
                    <thead><tr><th>Giáo viên</th><th>Trạng thái</th><th class="text-right">Đơn giá</th><th>Hiệu lực từ</th><th class="text-right"><span class="sr-only">Thao tác</span></th></tr></thead>
                    <tbody>
                        @forelse ($teachers as $teacher)
                            @php
                                $current = $currentRates->get($teacher->id);
                                $detailUrl = route('payroll.config.teacher-rates', ['teacher_id' => $teacher->id]);
                            @endphp
                            <tr x-show="match({{ $teacher->id }})" data-href="{{ $detailUrl }}" @class(['cursor-pointer', 'bg-primary-fixed/40' => $selectedTeacher?->id === $teacher->id])>
                                <td>
                                    <a href="{{ $detailUrl }}" class="flex items-center gap-sm hover:text-primary">
                                        <x-ui.avatar :name="$teacher->name" size="sm" />
                                        <span>
                                            <span class="block font-semibold">{{ $teacher->name }}</span>
                                            <span class="block font-caption text-caption text-on-surface-variant">Mã NV: {{ $teacher->employee_code ?: '—' }}</span>
                                        </span>
                                    </a>
                                </td>
                                <td><x-ui.badge :color="$teacher->is_active ? 'success' : 'neutral'" pill>{{ $teacher->is_active ? 'Đang giảng dạy' : 'Ngừng hoạt động' }}</x-ui.badge></td>
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
                                <td class="text-right"><x-ui.button variant="secondary" size="sm" icon="visibility" :href="$detailUrl">Xem</x-ui.button></td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-ui.empty-state icon="person_off" title="Chưa có nhân sự giảng dạy" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-ui.data-table>
        </div>

        {{-- Lịch sử thay đổi đơn giá của mọi GV (đơn giá mới nhập bằng nút "Cập nhật đơn giá" → modal) --}}
        @include('payroll.partials.rate-history-table', ['rows' => $history, 'withTeacher' => true, 'title' => 'Lịch sử thay đổi đơn giá', 'paginator' => $history])

        {{-- Khung đơn giá theo cấp bậc (tham khảo khi đặt giá cho GV) --}}
        <details class="rounded-xl border border-outline-variant bg-surface-container-lowest" @if (old('_modal') === 'new-rank') open @endif>
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

                <div class="flex justify-end">
                    <x-ui.button variant="secondary" icon="add" x-on:click="$dispatch('open-modal', 'new-rank')">Thêm cấp bậc tham khảo</x-ui.button>
                </div>
            </div>
        </details>
    </div>

    @if ($selectedTeacher)
        {{-- Chi tiết đơn giá 1 GV: mở sẵn khi URL có ?teacher_id=; đóng → bỏ teacher_id khỏi thanh địa chỉ. --}}
        <x-ui.modal name="teacher-rate-detail" :title="'Đơn giá — '.$selectedTeacher->name" max-width="4xl" show
                    :dismiss-url="route('payroll.config.teacher-rates', request()->except('teacher_id'))">
            <div class="space-y-lg" data-teacher-rate="{{ $selectedTeacher->id }}">
                <dl class="grid grid-cols-1 gap-md rounded-lg border border-outline-variant bg-surface-container-low p-md sm:grid-cols-3">
                    <div>
                        <dt class="font-body-small text-body-small text-on-surface-variant">Mã nhân viên</dt>
                        <dd class="mt-xs font-code text-on-surface">{{ $selectedTeacher->employee_code ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-body-small text-body-small text-on-surface-variant">Loại giáo viên</dt>
                        <dd class="mt-xs inline-flex items-center gap-xs font-body-medium text-body-medium text-on-surface">
                            <span class="material-symbols-outlined text-[18px] text-primary-container" aria-hidden="true">badge</span>
                            {{ \App\Models\TeacherHourlyRate::TEACHER_TYPES[$selectedType] ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-body-small text-body-small text-on-surface-variant">Mức lương đang áp dụng</dt>
                        <dd class="font-h3 text-h3 text-primary">
                            @if ($selectedCurrent)
                                {{ number_format((float) $selectedCurrent->hourly_rate, 0, ',', '.') }} {{ $unitSuffix[$selectedCurrent->rate_unit] ?? 'VNĐ / giờ' }}
                                <span class="block font-body-small text-body-small text-on-surface-variant">Hiệu lực từ: {{ $selectedCurrent->effective_from->format('d/m/Y') }}</span>
                            @elseif ((float) $selectedTeacher->hourly_rate > 0)
                                {{ \App\Support\Money::format((float) $selectedTeacher->hourly_rate) }} / giờ
                                <span class="block font-caption text-caption text-on-surface-variant">theo hồ sơ nhân sự</span>
                            @else
                                <span class="font-body-medium text-body-medium text-on-surface-variant">Chưa có đơn giá riêng (mặc định {{ \App\Support\Money::format(\App\Models\TeacherTimesheet::DEFAULT_HOURLY_RATE) }} / giờ)</span>
                            @endif
                        </dd>
                    </div>
                </dl>

                @include('payroll.partials.rate-history-table', ['rows' => $teacherHistory, 'withTeacher' => false, 'title' => 'Lịch sử thay đổi đơn giá'])
            </div>
            <x-slot:footer>
                <x-ui.button icon="price_change" x-on:click="$dispatch('open-modal', 'new-rate')">Cập nhật đơn giá</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    <x-ui.modal name="new-rate" :title="'Cập nhật đơn giá mới'.($selectedTeacher ? ' — '.$selectedTeacher->name : '')" max-width="2xl" :show="old('_modal') === 'new-rate'">
        <form id="new-rate-form" action="{{ route('payroll.config.teacher-rates.personal.store') }}" method="POST" class="space-y-md"
              x-data="{ unit: @js(old('rate_unit', 'session')), type: @js(old('teacher_type', $selectedType ?? 'parttime')) }">
            @csrf
            <input type="hidden" name="_modal" value="new-rate">

            @if ($selectedTeacher)
                <input type="hidden" name="user_id" value="{{ $selectedTeacher->id }}">
            @else
                <x-ui.select name="user_id" label="Giáo viên" required placeholder="-- Chọn giáo viên --"
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
                               class="w-full rounded-lg border {{ $errors->has('hourly_rate') ? 'border-error' : 'border-outline-variant' }} bg-surface-container-lowest px-md py-sm text-right font-mono text-body-base focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/50">
                        <span class="whitespace-nowrap font-body-medium text-body-medium text-on-surface-variant" x-text="unit === 'session' ? 'VNĐ / buổi' : 'VNĐ / giờ'">VNĐ / buổi</span>
                    </div>
                </x-ui.field>
            </div>
            <x-ui.textarea name="note" label="Ghi chú / Lý do thay đổi" rows="2" placeholder="Nhập ghi chú nếu có..." />

            <p x-show="type === 'fulltime'" x-cloak class="rounded-lg bg-warning-container px-md py-sm font-body-small text-body-small text-on-warning-container">
                GV Full-time hưởng lương cơ bản — đơn giá buổi chỉ dùng để đối soát, không cộng vào lương.
            </p>
            <p x-show="type === 'foreign'" x-cloak class="rounded-lg bg-warning-container px-md py-sm font-body-small text-body-small text-on-warning-container">
                Lương buổi có GVNN đang chờ BA chốt cách tính — Kế toán nhập tay trên phiếu lương.
            </p>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'new-rate')">Hủy bỏ</x-ui.button>
            <x-ui.button type="submit" form="new-rate-form" icon="save">Cập nhật đơn giá mới</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal name="new-rank" title="Thêm cấp bậc tham khảo" max-width="xl" :show="old('_modal') === 'new-rank'">
        <form id="new-rank-form" action="{{ route('payroll.config.teacher-rates.store') }}" method="POST" class="grid grid-cols-1 gap-md sm:grid-cols-2">
            @csrf
            <input type="hidden" name="_modal" value="new-rank">
            <x-ui.input name="rank_title" label="Cấp bậc" required placeholder="VD: Senior IELTS Trainer" />
            <x-ui.input name="criteria" label="Yêu cầu chứng chỉ & kinh nghiệm" placeholder="IELTS 8.0+, 3 năm KN" />
            <x-ui.input type="number" name="communication_rate" label="Lớp Giao tiếp (VNĐ/giờ)" required step="10000" />
            <x-ui.input type="number" name="ielts_rate" label="Lớp IELTS / Cambridge (VNĐ/giờ)" required step="10000" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'new-rank')">Hủy</x-ui.button>
            <x-ui.button type="submit" form="new-rank-form" icon="add">Thêm cấp bậc</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-app-layout>
