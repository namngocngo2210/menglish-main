<x-app-layout>
    <x-ui.page-header title="Cấu hình mốc hoa hồng tuyển sinh"
                      description="Hoa hồng = % theo bậc (bậc chọn theo SỐ HS CHỐT của sale trong kỳ) × tiền thực thu của khách mới (gồm giáo trình, đồ dùng). Hệ thống tự tính, không sửa tay.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="price_change" :href="route('payroll.config.teacher-rates')">Đơn giá GV</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-lg" x-data="{ editing: null }">
        @if ($errors->any())
            <x-ui.alert type="error">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        <x-ui.alert type="warning" title="Lịch sử & thời điểm áp dụng">
            Sửa một mốc sẽ tạo <strong>phiên bản mới</strong> có hiệu lực từ ngày bạn chọn; phiên bản cũ được đóng vào ngày hôm trước và vẫn được dùng
            khi tính lại các kỳ lương trước đó. Kỳ lương dùng mốc hiệu lực tại <strong>ngày cuối kỳ</strong>.
        </x-ui.alert>

        <x-ui.alert type="info" title="Gate kép — hoãn, không mất">
            Hoa hồng của từng khách chỉ được trả khi <strong>đủ {{ config('payroll.commission.gate_days') }} ngày từ ngày chốt</strong> và
            <strong>đủ {{ config('payroll.commission.gate_milestones') }}/3 mốc chăm sóc tháng đầu</strong> (buổi 1, buổi 4–5, đủ 30 ngày).
            Chưa đủ thì khoản hoa hồng (giữ % của kỳ phát sinh) được <strong>hoãn sang kỳ sau</strong> và trả ở kỳ đầu tiên đạt điều kiện.
            Ngưỡng số HS mặc định (0–5 / 6–10 / từ 11) đang chờ BA xác nhận.
        </x-ui.alert>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-lg items-start">
            <div class="lg:col-span-8 space-y-lg">
                <x-ui.data-table min-width="640px">
                    <x-slot:header>
                        <h3 class="font-h3 text-h3 text-on-surface">Mốc đang hiệu lực ngày {{ $asOf->format('d/m/Y') }}</h3>
                        <form method="GET" class="flex items-center gap-sm">
                            <x-ui.date name="as_of" inline-label="Xem tại ngày:" :value="$asOf->toDateString()" />
                            <x-ui.button type="submit" variant="secondary" size="sm">Xem</x-ui.button>
                        </form>
                    </x-slot:header>
                    <table>
                        <thead>
                            <tr>
                                <th>Bậc</th>
                                <th class="text-right">Số HS chốt trong kỳ</th>
                                <th class="text-center">% Hoa hồng</th>
                                <th>Hiệu lực từ</th>
                                <th class="text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($tiers as $tier)
                                <tr>
                                    <td class="font-semibold">{{ $tier->tier_name }}</td>
                                    <td class="text-right font-code text-code">{{ $tier->student_range_label }}</td>
                                    <td class="text-center"><x-ui.badge color="success">{{ rtrim(rtrim(number_format((float) $tier->new_sale_percent, 2, '.', ''), '0'), '.') }}%</x-ui.badge></td>
                                    <td class="font-code text-code">{{ $tier->effective_from?->format('d/m/Y') ?? 'Từ đầu' }}</td>
                                    <td class="text-right">
                                        @if ($tier->effective_to === null)
                                            <div class="flex justify-end gap-xs">
                                                <x-ui.button variant="ghost" size="sm" icon="edit"
                                                             @click="editing = {{ Js::from($tier->only(['id', 'tier_name', 'min_students', 'max_students', 'new_sale_percent'])) }}; $dispatch('open-modal', 'edit-tier')">Phiên bản mới</x-ui.button>
                                                <form action="{{ route('payroll.config.commission-tiers.destroy', $tier) }}" method="POST" data-confirm="Ngừng áp dụng mốc {{ $tier->tier_name }} từ hôm nay?">
                                                    @csrf
                                                    @method('DELETE')
                                                    <x-ui.button type="submit" variant="danger-text" size="sm" icon="block">Ngừng</x-ui.button>
                                                </form>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><x-ui.empty-state icon="percent" title="Chưa có mốc hoa hồng hiệu lực tại ngày này" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-ui.data-table>

                <x-ui.data-table min-width="760px">
                    <x-slot:header>
                        <h3 class="font-h3 text-h3 text-on-surface">Lịch sử các phiên bản</h3>
                    </x-slot:header>
                    <table>
                        <thead>
                            <tr>
                                <th>Bậc</th>
                                <th class="text-right">Số HS chốt</th>
                                <th class="text-center">% Hoa hồng</th>
                                <th>Hiệu lực</th>
                                <th>Người tạo</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($history as $version)
                                <tr>
                                    <td>
                                        <span class="font-semibold">{{ $version->tier_name }}</span>
                                        @if ($version->replaces)
                                            <span class="block font-caption text-caption text-on-surface-variant">thay cho “{{ $version->replaces->tier_name }}”</span>
                                        @endif
                                    </td>
                                    <td class="text-right font-code text-code">{{ $version->student_range_label }}</td>
                                    <td class="text-center font-code text-code">{{ rtrim(rtrim(number_format((float) $version->new_sale_percent, 2, '.', ''), '0'), '.') }}%</td>
                                    <td class="font-code text-code whitespace-nowrap">
                                        {{ $version->effective_from?->format('d/m/Y') ?? 'Từ đầu' }} → {{ $version->effective_to?->format('d/m/Y') ?? 'nay' }}
                                        @if ($version->effective_to === null)
                                            <x-ui.badge color="success">Đang áp dụng</x-ui.badge>
                                        @endif
                                    </td>
                                    <td>{{ $version->creator?->name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><x-ui.empty-state icon="history" title="Chưa có lịch sử" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <x-slot:footer><x-ui.pagination :paginator="$history" /></x-slot:footer>
                </x-ui.data-table>
            </div>

            <div class="lg:col-span-4">
                <form action="{{ route('payroll.config.commission-tiers.store') }}" method="POST"
                      class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg space-y-md shadow-sm">
                    @csrf
                    <h3 class="font-h3 text-h3 text-on-surface">Thêm mốc hoa hồng</h3>
                    <x-ui.input name="tier_name" label="Tên bậc" required placeholder="VD: Bậc 4 (Kim Cương)" />
                    <x-ui.input type="number" name="min_students" label="Số HS chốt trong kỳ — từ" required min="0" step="1" />
                    <x-ui.input type="number" name="max_students" label="Số HS chốt — đến" min="0" step="1" hint="Bỏ trống = không giới hạn." />
                    <x-ui.input type="number" name="new_sale_percent" label="% Hoa hồng khách mới" required min="0" max="100" step="0.1" />
                    <x-ui.date name="effective_from" label="Hiệu lực từ ngày" required :value="old('effective_from', now()->toDateString())" />
                    <x-ui.button type="submit" icon="save" class="w-full">Lưu mốc mới</x-ui.button>
                    <p class="font-caption text-caption text-on-surface-variant">Không có hoa hồng tái tục (A6). Thưởng tái tục của GV phụ trách lớp cấu hình ở Tham số tính lương.</p>
                </form>
            </div>
        </div>

        <x-ui.modal name="edit-tier" title="Tạo phiên bản mới của mốc hoa hồng">
            <form id="edit-tier-form" method="POST" :action="editing ? '{{ url('payroll/config/commission-tiers') }}/' + editing.id : '#'" class="space-y-md">
                @csrf
                @method('PUT')
                <template x-if="editing">
                    <div class="space-y-md">
                        <x-ui.field label="Tên bậc" required>
                            <input type="text" name="tier_name" x-model="editing.tier_name" required class="w-full rounded-lg border border-outline-variant px-md py-sm font-body-base text-body-base">
                        </x-ui.field>
                        <div class="grid grid-cols-2 gap-md">
                            <x-ui.field label="Số HS chốt — từ" required>
                                <input type="number" name="min_students" x-model="editing.min_students" min="0" required class="w-full rounded-lg border border-outline-variant px-md py-sm font-code text-code">
                            </x-ui.field>
                            <x-ui.field label="Số HS chốt — đến">
                                <input type="number" name="max_students" x-model="editing.max_students" min="0" class="w-full rounded-lg border border-outline-variant px-md py-sm font-code text-code">
                            </x-ui.field>
                            <x-ui.field label="% Hoa hồng" required>
                                <input type="number" name="new_sale_percent" x-model="editing.new_sale_percent" min="0" max="100" step="0.1" required class="w-full rounded-lg border border-outline-variant px-md py-sm font-code text-code">
                            </x-ui.field>
                        </div>
                        <x-ui.date name="effective_from" label="Phiên bản mới hiệu lực từ" required :value="now()->addDay()->toDateString()"
                                   hint="Phiên bản cũ tự đóng vào ngày trước đó." />
                    </div>
                </template>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'edit-tier')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="edit-tier-form" icon="save">Lưu phiên bản mới</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-app-layout>
