{{-- Nhập điểm mini test (mockup 03_Cong_Giao_Vien/06_nhap_diem_mini_test): Chọn Unit + Chọn học sinh, điểm 4 kỹ năng (bắt buộc đủ),
     nhận xét chung. Bên dưới: bảng điểm đã nhập của Unit đang chọn (bấm để sửa). --}}
@php
    $skills = \App\Models\MiniTestScore::SKILLS;
    $selected = old('student_id', $selectedStudentId);
    $current = $selected ? $existing->get((int) $selected) : null;
    $input = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20';
    $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
@endphp
<x-app-layout title="Nhập điểm mini test — {{ $class->name }}">
    <div class="mx-auto max-w-3xl space-y-lg pb-24 md:pb-0">
        <x-ui.page-header title="Nhập điểm mini test" :back="route('teacher.home')" back-label="Về lịch dạy">
            <x-slot:meta>
                Vui lòng chọn thông tin và nhập điểm cho học sinh · Lớp {{ $class->name }} <span class="font-code">({{ $class->code }})</span>
            </x-slot:meta>
        </x-ui.page-header>

        @if ($class->students->isEmpty())
            <div class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                <x-ui.empty-state icon="group_off" title="Lớp chưa có học sinh" description="Lớp này chưa có học sinh nào trong danh sách." />
            </div>
        @else
            <form method="POST" action="{{ route('teacher.scores.store', $class->id) }}" class="space-y-lg rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm md:p-lg">
                @csrf
                @if ($errors->any())
                    <x-ui.alert type="error">{{ $errors->first() }}</x-ui.alert>
                @endif

                <section class="grid grid-cols-1 gap-md md:grid-cols-2">
                    @if ($units->isNotEmpty())
                        <x-ui.select label="Chọn Unit" id="unitSelect" name="unit_id" required
                                     onchange="window.location = {{ Js::from(route('teacher.scores', $class->id)) }} + '?unit_id=' + this.value">
                                <option value="" disabled @selected(! $unit)>Chọn Unit bài học</option>
                                @foreach ($units as $u)
                                    <option value="{{ $u->id }}" @selected($unit && $unit->id === $u->id)>Unit {{ $u->unit_number }}: {{ $u->title }}</option>
                                @endforeach
                        </x-ui.select>
                    @else
                        <x-ui.field label="Tên bài kiểm tra" name="unit_id" for="testName" required hint="Lớp chưa gắn giáo trình có Unit — nhập tên bài.">
                            <input id="testName" name="name" required value="{{ old('name', $testName) }}" class="{{ $input }}">
                        </x-ui.field>
                    @endif
                    <x-ui.select label="Chọn học sinh" id="studentSelect" name="student_id" required>
                            <option value="" disabled @selected(! $selected)>Chọn học sinh từ danh sách</option>
                            @foreach ($class->students as $student)
                                <option value="{{ $student->id }}" @selected((string) $selected === (string) $student->id)>{{ $student->name }}{{ $existing->has($student->id) ? ' ✓' : '' }}</option>
                            @endforeach
                    </x-ui.select>
                </section>

                <hr class="border-surface-container">

                <section class="space-y-md">
                    <div class="flex items-center justify-between gap-sm">
                        <h2 class="font-h3 text-h3 text-on-surface">Điểm kỹ năng</h2>
                        <label class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant">
                            Thang điểm tối đa
                            <input type="number" name="max_score" value="{{ old('max_score', $current?->max_score ? $fmt($current->max_score) : 10) }}" min="1" max="100" step="0.5"
                                   class="w-20 rounded-lg border border-outline-variant px-sm py-xs text-center font-code">
                        </label>
                    </div>
                    <div class="grid grid-cols-2 gap-md md:grid-cols-4">
                        @foreach ($skills as $key => $label)
                            <x-ui.field :label="$label" name="skills" :for="'score_'.$key" required>
                                <input id="score_{{ $key }}" type="number" name="skills[{{ $key }}]" min="0" step="0.1" required placeholder="Nhập điểm"
                                       value="{{ old('skills.'.$key, isset($current?->skill_scores[$key]) ? $fmt($current->skill_scores[$key]) : '') }}"
                                       class="{{ $input }} text-center font-code {{ $errors->has('skills') && blank(old('skills.'.$key)) ? 'border-error ring-2 ring-error/20' : '' }}">
                            </x-ui.field>
                        @endforeach
                    </div>
                </section>

                <x-ui.textarea name="note" label="Nhận xét chung (Không bắt buộc)" rows="3" :value="$current?->note"
                    placeholder="Nhập nhận xét về phần làm bài của học sinh..." />
                <input type="hidden" name="test_date" value="{{ $testDate }}">

                <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
                    <x-ui.button variant="secondary" :href="route('teacher.home')">Hủy</x-ui.button>
                    <x-ui.button type="submit" icon="save">Lưu điểm</x-ui.button>
                </div>
            </form>

            @if ($testName)
                <x-ui.data-table>
                    <x-slot:header>
                        <h3 class="font-h3 text-h3 text-on-surface">Điểm đã nhập — {{ $testName }}</h3>
                        <span class="font-body-small text-body-small text-on-surface-variant">{{ $existing->count() }}/{{ $class->students->count() }} học sinh</span>
                    </x-slot:header>
                    <table>
                        <thead>
                            <tr>
                                <th>Học sinh</th>
                                @foreach ($skills as $label)<th class="text-center">{{ $label }}</th>@endforeach
                                <th class="text-center">Tổng (TB)</th>
                                <th class="text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($class->students as $student)
                                @php $sc = $existing->get($student->id); $low = $sc && (float) $sc->max_score > 0 && (float) $sc->score * 10 < (float) $sc->max_score * 7; @endphp
                                <tr>
                                    <td class="font-semibold">{{ $student->name }}</td>
                                    @foreach ($skills as $key => $label)
                                        <td class="text-center font-code">{{ $fmt($sc?->skill_scores[$key] ?? null) }}</td>
                                    @endforeach
                                    <td class="text-center font-code font-semibold {{ $low ? 'text-error' : '' }}">{{ $sc ? $fmt($sc->score).'/'.$fmt($sc->max_score) : 'Chưa nhập' }}</td>
                                    <td class="text-right">
                                        <x-ui.button size="sm" variant="ghost" icon="edit"
                                            :href="route('teacher.scores', array_filter(['classId' => $class->id, 'unit_id' => $unit?->id, 'name' => $unit ? null : $testName, 'student_id' => $student->id]))">{{ $sc ? 'Sửa' : 'Nhập' }}</x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <x-slot:footer>
                        <p class="px-md py-sm font-caption text-caption text-on-surface-variant">Điểm tổng dưới 7/10 tự đưa học sinh vào danh sách bổ trợ.</p>
                    </x-slot:footer>
                </x-ui.data-table>
            @endif
        @endif
    </div>

    @include('teacher.partials.bottom-nav')
</x-app-layout>
