<x-app-layout>
    <x-ui.page-header title="Cấu hình đơn giá giáo viên"
                      description="Đơn giá riêng từng giáo viên theo ngày hiệu lực — GV Part-time tính theo BUỔI (Q3). Đổi giá = thêm phiên bản mới, buổi dạy cũ vẫn tính theo giá cũ.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="percent" :href="route('payroll.config.commission-tiers')">Cấu hình hoa hồng</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($errors->any())
        <x-ui.alert type="error" class="mb-md">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-lg items-start">
        {{-- 1. Thêm đơn giá mới cho một GV --}}
        <div class="lg:col-span-4 space-y-md">
            <form action="{{ route('payroll.config.teacher-rates.personal.store') }}" method="POST"
                  class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md shadow-sm">
                @csrf
                <h3 class="font-h3 text-h3 text-on-surface flex items-center gap-xs">
                    <span class="material-symbols-outlined text-primary-container">edit_calendar</span>
                    Thêm đơn giá mới
                </h3>

                <x-ui.select name="user_id" label="Giáo viên" required placeholder="-- Chọn giáo viên --"
                             :value="old('user_id', $selectedTeacher?->id)"
                             :options="$teachers->mapWithKeys(fn ($t) => [$t->id => $t->name.' ('.$t->email.')'])" />
                <x-ui.select name="rate_unit" label="Tính theo" required :value="old('rate_unit', 'session')"
                             :options="['session' => 'Theo buổi dạy (đ/buổi) — Part-time Q3', 'hour' => 'Theo giờ (đ/giờ) — cách cũ']" />
                <x-ui.input type="number" name="hourly_rate" label="Đơn giá (VNĐ)" required min="1000" step="1000" placeholder="VD: 350000" />
                <x-ui.date name="effective_from" label="Hiệu lực từ ngày" required :value="old('effective_from', now()->toDateString())"
                           hint="Áp dụng cho các ca dạy từ ngày này cho tới khi có đơn giá mới hơn." />
                <x-ui.textarea name="note" label="Ghi chú" rows="2" placeholder="VD: Tăng bậc sau đánh giá quý 3" />

                <x-ui.button type="submit" icon="save" class="w-full">Lưu phiên bản đơn giá</x-ui.button>

                <p class="font-caption text-caption text-on-surface-variant">
                    GV Part-time: mỗi buổi chấm công hợp lệ × đơn giá buổi hiệu lực tại ngày dạy. Chưa có đơn giá buổi thì tính
                    số giờ × đơn giá giờ (ghi riêng trên ca dạy → đơn giá giờ của GV → hồ sơ nhân sự → mặc định
                    {{ number_format(\App\Models\TeacherTimesheet::DEFAULT_HOURLY_RATE, 0, ',', '.') }}đ/h). GV Full-time hưởng lương cơ bản, không tính theo buổi.
                </p>
            </form>
        </div>

        <div class="lg:col-span-8 space-y-lg">
            {{-- 2. Đơn giá đang hiệu lực --}}
            <x-ui.data-table min-width="560px">
                <x-slot:header>
                    <h3 class="font-h3 text-h3 text-on-surface">Đơn giá đang hiệu lực ({{ now()->format('d/m/Y') }})</h3>
                </x-slot:header>
                <table>
                    <thead>
                        <tr>
                            <th>Giáo viên</th>
                            <th class="text-right">Đơn giá</th>
                            <th>Hiệu lực từ</th>
                            <th class="text-right">Lịch sử</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($teachers as $teacher)
                            @php $current = $currentRates->get($teacher->id); @endphp
                            <tr>
                                <td>
                                    <p class="font-semibold">{{ $teacher->name }}</p>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ $teacher->getRoleNames()->implode(', ') }}</p>
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
                                <td class="text-right">
                                    <x-ui.button variant="ghost" size="sm" icon="history" :href="route('payroll.config.teacher-rates', ['teacher_id' => $teacher->id])">Xem</x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><x-ui.empty-state icon="person_off" title="Chưa có nhân sự giảng dạy" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-ui.data-table>

            {{-- 3. Lịch sử thay đổi đơn giá --}}
            <x-ui.data-table min-width="640px">
                <x-slot:header>
                    <h3 class="font-h3 text-h3 text-on-surface">
                        Lịch sử đơn giá{{ $selectedTeacher ? ': '.$selectedTeacher->name : '' }}
                    </h3>
                    @if ($selectedTeacher)
                        <x-ui.button variant="ghost" size="sm" icon="filter_alt_off" :href="route('payroll.config.teacher-rates')">Xem tất cả</x-ui.button>
                    @endif
                </x-slot:header>
                <table>
                    <thead>
                        <tr>
                            <th>Giáo viên</th>
                            <th class="text-right">Đơn giá</th>
                            <th>Hiệu lực từ</th>
                            <th>Ghi chú</th>
                            <th>Người tạo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($history as $row)
                            <tr>
                                <td class="font-semibold">{{ $row->user?->name ?? '—' }}</td>
                                <td><x-ui.money :value="$row->hourly_rate" :suffix="$row->unit_label" /></td>
                                <td class="font-code text-code">{{ $row->effective_from->format('d/m/Y') }}</td>
                                <td>{{ $row->note ?? '—' }}</td>
                                <td>
                                    {{ $row->creator?->name ?? 'Hệ thống' }}
                                    <span class="block font-caption text-caption text-on-surface-variant">{{ $row->created_at?->format('d/m/Y H:i') }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-ui.empty-state icon="history" title="Chưa có lịch sử đơn giá" description="Thêm đơn giá mới ở khung bên trái." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                <x-slot:footer><x-ui.pagination :paginator="$history" /></x-slot:footer>
            </x-ui.data-table>

            {{-- 4. Khung đơn giá theo cấp bậc (tham khảo khi đặt giá cho GV) --}}
            <details class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                <summary class="cursor-pointer px-lg py-md font-body-medium text-body-medium text-on-surface">
                    Khung đơn giá tham khảo theo cấp bậc ({{ $rates->count() }} bậc)
                </summary>
                <div class="border-t border-surface-container p-lg space-y-md">
                    <x-ui.data-table>
                        <table>
                            <thead>
                                <tr>
                                    <th>Cấp bậc</th>
                                    <th>Yêu cầu</th>
                                    <th class="text-right">Lớp Giao tiếp</th>
                                    <th class="text-right">Lớp IELTS / Cambridge</th>
                                </tr>
                            </thead>
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

                    <form action="{{ route('payroll.config.teacher-rates.store') }}" method="POST" class="grid grid-cols-1 sm:grid-cols-2 gap-md">
                        @csrf
                        <x-ui.input name="rank_title" label="Cấp bậc" required placeholder="VD: Senior IELTS Trainer" />
                        <x-ui.input name="criteria" label="Yêu cầu chứng chỉ & kinh nghiệm" placeholder="IELTS 8.0+, 3 năm KN" />
                        <x-ui.input type="number" name="communication_rate" label="Lớp Giao tiếp (VNĐ/giờ)" required step="10000" />
                        <x-ui.input type="number" name="ielts_rate" label="Lớp IELTS / Cambridge (VNĐ/giờ)" required step="10000" />
                        <div class="sm:col-span-2 flex justify-end">
                            <x-ui.button type="submit" variant="secondary" icon="add">Thêm cấp bậc tham khảo</x-ui.button>
                        </div>
                    </form>
                </div>
            </details>
        </div>
    </div>
</x-app-layout>
