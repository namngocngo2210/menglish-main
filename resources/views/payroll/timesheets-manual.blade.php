{{-- Mockup: ui-full-tinh-nang-menglish/epic-7/cham-cong-thu-cong --}}
<x-app-layout>
    @php
        $classOptions = $classes->map(fn ($cl) => ['id' => $cl->id, 'label' => $cl->name.' ('.$cl->code.')', 'branch' => $cl->branch_id])->values();
        $teacherOptions = $teachers->map(fn ($tc) => [
            'id' => $tc->id,
            'label' => $tc->name.($tc->employee_code ? ' — '.$tc->employee_code : '').' ('.($tc->getRoleNames()->map(fn ($r) => \App\Helpers\AclHelper::roleLabel($r))->implode(', ') ?: 'GV/TA').')',
            'search' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($tc->name.' '.$tc->employee_code.' '.$tc->email)),
        ])->values();
        $defaultBranch = old('branch_id', request('branch_id') ?: ($branches->count() === 1 ? $branches->first()->id : (auth()->user()->branch_id && $branches->contains('id', auth()->user()->branch_id) ? auth()->user()->branch_id : '')));
    @endphp

    <div class="mx-auto max-w-3xl"
         x-data="{
            teachers: @js($teacherOptions),
            classes: @js($classOptions),
            locked: @js($lockedRanges),
            q: '',
            userId: @js((string) old('user_id', request('user_id', ''))),
            branchId: @js((string) $defaultBranch),
            classId: @js((string) old('class_id', request('class_id', ''))),
            date: @js(old('teaching_date', request('teaching_date', date('Y-m-d')))),
            get filteredTeachers() {
                const q = this.q.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd');
                return q ? this.teachers.filter(t => t.search.includes(q) || String(t.id) === this.userId) : this.teachers;
            },
            get filteredClasses() { return this.branchId ? this.classes.filter(c => String(c.branch) === this.branchId) : this.classes; },
            get lockedPeriod() { return this.locked.find(p => this.date && this.date >= p.from && this.date <= p.to) || null; },
         }">
        <x-ui.page-header title="Chấm công thủ công"
                          description="Ghi nhận chấm công thay hệ thống khi gặp sự cố hạ tầng (mất mạng, mất điện...).">
            <x-slot:breadcrumbs>
                <a href="{{ route('payroll.timesheets.teachers') }}" class="hover:text-primary">Chấm công giáo viên</a>
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">chevron_right</span>
                <span>Chấm công thủ công</span>
            </x-slot:breadcrumbs>
        </x-ui.page-header>

        <form action="{{ route('payroll.timesheets.manual.store') }}" method="POST"
              class="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest shadow-sm">
            @csrf
            <div class="flex items-center gap-sm border-b border-surface-container px-lg py-md">
                <span class="material-symbols-outlined text-primary-container" aria-hidden="true">timer</span>
                <h2 class="font-h3 text-h3 text-on-surface">Thông tin chấm công</h2>
            </div>

            <div class="space-y-lg p-lg">
                <x-ui.alert type="info">
                    <strong>Lưu ý:</strong> Mỗi lần lưu hệ thống chỉ tạo đúng <strong>1 dòng chấm công</strong> (tương ứng 1 buổi công).
                    Nếu giáo viên làm nhiều ca liên tiếp, vui lòng thực hiện lưu nhiều lần.
                    Không chấm trùng ca đã check-in / đã chấm tay; số giờ tính từ giờ vào – giờ ra (tối thiểu 30 phút).
                </x-ui.alert>

                <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <x-ui.field label="Nhân viên" name="user_id" for="f_user_id" required class="md:col-span-2">
                        <div class="relative mb-xs">
                            <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">search</span>
                            <input type="search" x-model="q" placeholder="Nhập tên hoặc mã nhân viên" aria-label="Tìm nhân viên"
                                   class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-10 pr-md font-body-base text-body-base text-on-surface placeholder:text-on-surface-variant/60 focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
                        </div>
                        <select name="user_id" id="f_user_id" required x-model="userId"
                                class="w-full rounded-lg border {{ $errors->has('user_id') ? 'border-error' : 'border-outline-variant' }} bg-surface-container-lowest py-sm pl-md pr-xl font-body-base text-body-base text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
                            <option value="">Chọn nhân viên...</option>
                            @foreach ($teacherOptions as $tc)
                                <option value="{{ $tc['id'] }}" @selected((string) old('user_id', request('user_id')) === (string) $tc['id'])
                                        x-show="filteredTeachers.some(t => t.id === {{ $tc['id'] }})">{{ $tc['label'] }}</option>
                            @endforeach
                        </select>
                        <p class="font-body-small text-body-small text-on-surface-variant" x-show="q && filteredTeachers.length === 0" x-cloak>Không tìm thấy nhân viên phù hợp.</p>
                    </x-ui.field>

                    <x-ui.field label="Chi nhánh" name="branch_id" for="f_branch_id" required>
                        <select name="branch_id" id="f_branch_id" x-model="branchId" @change="if (classId && ! filteredClasses.some(c => String(c.id) === classId)) classId = ''"
                                class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-md pr-xl font-body-base text-body-base text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
                            <option value="">Chọn chi nhánh...</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" @selected((string) $defaultBranch === (string) $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>

                    <x-ui.field label="Lớp học" name="class_id" for="f_class_id" required>
                        <select name="class_id" id="f_class_id" required x-model="classId"
                                class="w-full rounded-lg border {{ $errors->has('class_id') ? 'border-error' : 'border-outline-variant' }} bg-surface-container-lowest py-sm pl-md pr-xl font-body-base text-body-base text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
                            <option value="">Chọn lớp học...</option>
                            @foreach ($classOptions as $cl)
                                <option value="{{ $cl['id'] }}" @selected((string) old('class_id', request('class_id')) === (string) $cl['id'])
                                        x-show="! branchId || branchId === '{{ $cl['branch'] }}'">{{ $cl['label'] }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>

                    <div class="md:col-span-2">
                        <x-ui.date name="teaching_date" label="Ngày làm việc" required :value="old('teaching_date', request('teaching_date', date('Y-m-d')))" x-model="date" />
                        <div x-show="lockedPeriod" x-cloak role="alert"
                             class="mt-xs flex items-center gap-xs rounded-lg bg-error-container px-md py-sm font-body-small text-body-small text-on-error-container">
                            <span class="material-symbols-outlined text-[18px] text-error" aria-hidden="true">lock</span>
                            <span>Kỳ lương của ngày này đã bị khóa (<span x-text="lockedPeriod?.title"></span>). Không thể chấm công.</span>
                        </div>
                    </div>

                    <x-ui.input type="time" name="time_in" label="Giờ vào" required :value="request('time_in')" />
                    <x-ui.input type="time" name="time_out" label="Giờ ra" required :value="request('time_out')" />

                    <div class="md:col-span-2">
                        <x-ui.textarea name="notes" label="Lý do điều chỉnh" required rows="3"
                                       placeholder="Ví dụ: Mất mạng chi nhánh, quên quẹt thẻ..." />
                    </div>
                </div>

                <details class="rounded-lg border border-surface-container bg-surface-container-low/40 px-md py-sm" @if ($errors->has('type') || $errors->has('hourly_rate') || old('hourly_rate')) open @endif>
                    <summary class="cursor-pointer font-body-medium text-body-medium text-on-surface-variant">Thông tin bổ sung (loại ca, đơn giá riêng ca này)</summary>
                    <div class="mt-md grid grid-cols-1 gap-md md:grid-cols-2">
                        <x-ui.select name="type" label="Loại ca dạy" required :options="[
                            'regular' => 'Ca dạy chính khóa',
                            'sub' => 'Dạy thay (Sub)',
                            '1on1' => 'Kèm phụ đạo 1-1',
                            'grading' => 'Chấm bài thi Test',
                            'workshop' => 'Workshop / Sự kiện',
                        ]" />
                        <x-ui.input type="number" name="hourly_rate" label="Đơn giá giờ riêng cho ca này (đ/giờ)" min="1000" step="1000"
                                    placeholder="Bỏ trống = đơn giá của giáo viên"
                                    hint="Bỏ trống để dùng đơn giá riêng của GV theo ngày hiệu lực (theo buổi hoặc theo giờ)." />
                    </div>
                </details>
            </div>

            <div class="flex items-center justify-end gap-sm border-t border-surface-container bg-surface-container-low/40 px-lg py-md">
                <x-ui.button variant="secondary" :href="route('payroll.timesheets.teachers')">Hủy</x-ui.button>
                <x-ui.button type="submit" icon="save" x-bind:disabled="!! lockedPeriod">Lưu chấm công</x-ui.button>
            </div>
        </form>
    </div>
</x-app-layout>
