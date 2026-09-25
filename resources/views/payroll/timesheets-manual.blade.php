<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('payroll.timesheets.teachers') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight">Chấm công Ca dạy Thủ công (Giáo viên / Trợ giảng)</h1>
                <p class="text-xs text-gray-500">Ghi nhận ca dạy khi giáo viên không check-in được: bắt buộc giờ vào/ra và lý do</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-4xl mx-auto space-y-md">
        <x-ui.alert type="info" title="Quy tắc chấm công tay">
            <ul class="list-disc pl-5 space-y-0.5">
                <li>Số giờ được tính tự động từ <strong>giờ vào</strong> và <strong>giờ ra</strong> (tối thiểu 30 phút).</li>
                <li>Nếu lớp có buổi học trên lịch vào ngày này, bản ghi sẽ được gắn với buổi học đó.</li>
                <li>Không chấm trùng: nhân sự đã check-in hoặc đã được chấm tay cho cùng lớp/buổi sẽ bị từ chối.</li>
                <li>Không ghi được vào ngày thuộc kỳ lương đã duyệt/đã chi trả.</li>
            </ul>
        </x-ui.alert>

        <form action="{{ route('payroll.timesheets.manual.store') }}" method="POST" class="bg-surface-container-lowest rounded-xl border border-outline-variant shadow-sm p-lg space-y-lg">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-md">
                <x-ui.select name="user_id" label="Giáo viên / Trợ giảng được chấm công" required>
                    @foreach ($teachers as $tc)
                        <option value="{{ $tc->id }}" @selected((string) old('user_id') === (string) $tc->id)>
                            {{ $tc->name }} - [{{ $tc->getRoleNames()->implode(', ') ?: 'GV/TA' }}] ({{ $tc->email }})
                        </option>
                    @endforeach
                </x-ui.select>

                <x-ui.select name="class_id" label="Lớp học giảng dạy" required>
                    @foreach ($classes as $cl)
                        <option value="{{ $cl->id }}" @selected((string) old('class_id') === (string) $cl->id)>{{ $cl->name }} ({{ $cl->code }})</option>
                    @endforeach
                </x-ui.select>

                <x-ui.date name="teaching_date" label="Ngày giảng dạy" required :value="old('teaching_date', date('Y-m-d'))" />

                <x-ui.select name="type" label="Loại ca dạy" required :options="[
                    'regular' => 'Ca dạy chính khóa',
                    'sub' => 'Dạy thay (Sub)',
                    '1on1' => 'Kèm phụ đạo 1-1',
                    'grading' => 'Chấm bài thi Test',
                    'workshop' => 'Workshop / Sự kiện',
                ]" />

                <x-ui.input type="time" name="time_in" label="Giờ vào" required />
                <x-ui.input type="time" name="time_out" label="Giờ ra" required hint="Số giờ tính công = giờ ra − giờ vào." />

                <x-ui.input type="number" name="hourly_rate" label="Đơn giá giờ dạy riêng cho ca này (VNĐ/h)" min="1000" step="1000"
                            placeholder="Bỏ trống = đơn giá của giáo viên"
                            hint="Bỏ trống để dùng đơn giá riêng của GV theo ngày hiệu lực, rồi tới hồ sơ nhân sự (mặc định 250.000đ/h)." />

                <div class="md:col-span-2">
                    <x-ui.textarea name="notes" label="Lý do chấm công tay" required rows="3"
                                   placeholder="VD: GV quên check-in, dạy thay cho cô B, máy chấm công lỗi..." />
                </div>
            </div>

            <div class="flex items-center justify-end gap-sm pt-md border-t border-surface-container">
                <x-ui.button variant="secondary" :href="route('payroll.timesheets.teachers')">Hủy</x-ui.button>
                <x-ui.button type="submit" icon="save">Lưu chấm công</x-ui.button>
            </div>
        </form>
    </div>
</x-app-layout>
