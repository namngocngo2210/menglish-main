<x-app-layout>
    <x-ui.page-header title="Biên bản vi phạm & kỷ luật"
                      description="Ghi nhận vi phạm → nhân sự giải trình → HT/CM chốt theo loại lỗi → nộp phạt trong 2 ngày → quá hạn trừ vào kỳ lương.">
        <x-slot:actions>
            @can('violation.create')
                <x-ui.button icon="add_alert" @click="$dispatch('open-modal', 'new-penalty')">Ghi nhận vi phạm</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if ($errors->any())
        <x-ui.alert type="error" class="mb-md">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="mb-lg grid grid-cols-2 gap-md lg:grid-cols-4">
        <x-ui.stat-card label="Chờ giải trình" :value="$counts['pending'] ?? 0" icon="edit_note" tone="warning" />
        <x-ui.stat-card label="Chờ HT/CM chốt" :value="($counts['explained'] ?? 0) + ($counts['confirmed'] ?? 0)" icon="gavel" tone="secondary" />
        <x-ui.stat-card label="Đã quyết phạt (trong hạn nộp)" :value="max(0, ($counts['fined'] ?? 0) - $overdueCount)" icon="schedule" tone="primary" />
        <x-ui.stat-card label="Quá hạn — sẽ trừ lương" :value="$overdueCount" icon="money_off" tone="error" />
    </div>

    <x-ui.filter-bar placeholder="Tìm mã biên bản, nhân sự, lỗi vi phạm...">
        <x-ui.select name="status" placeholder="Tất cả bước" inline-label="Bước:"
                     :options="['open' => 'Đang xử lý (chưa đóng)', 'overdue' => 'Quá hạn nộp'] + \App\Models\Penalty::statusLabels()" />
        <x-ui.select name="category" placeholder="Tất cả loại lỗi" inline-label="Loại lỗi:"
                     :options="collect(\App\Models\Penalty::CATEGORIES)->map(fn ($c) => $c['label'])->all()" />
    </x-ui.filter-bar>

    <x-ui.data-table min-width="1100px">
        <table>
            <thead>
                <tr>
                    <th>Mã biên bản</th>
                    <th>Nhân sự vi phạm</th>
                    <th>Lỗi vi phạm</th>
                    <th>Ngày vi phạm</th>
                    <th class="text-right">Số tiền phạt</th>
                    <th>Hạn nộp</th>
                    <th>Trạng thái</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($penalties as $pen)
                    @php
                        $canDecide = auth()->user()->can('violation.confirm_fine') && $pen->canBeDecidedBy(auth()->user())
                            && in_array($pen->status, ['pending', 'explained', 'confirmed'], true);
                        $canExplain = $pen->user_id === auth()->id() && $pen->status === 'pending';
                    @endphp
                    <tr class="align-top">
                        <td class="font-code text-code font-semibold">{{ $pen->code }}</td>
                        <td>
                            <p class="font-semibold">{{ $pen->user?->name }}</p>
                            <p class="font-caption text-caption text-on-surface-variant">Lập bởi {{ $pen->reporter?->name ?? 'Hệ thống' }}</p>
                        </td>
                        <td class="max-w-xs">
                            <p class="font-medium text-error">{{ $pen->violation_type }}</p>
                            <p class="font-caption text-caption text-on-surface-variant">{{ $pen->category_label }} · chốt bởi {{ $pen->confirmer_label }}</p>
                            @if ($pen->explanation)
                                <p class="mt-xs rounded bg-surface-container-low p-xs font-caption text-caption text-on-surface" title="Giải trình">
                                    <span class="font-semibold">Giải trình:</span> {{ $pen->explanation }}
                                </p>
                            @endif
                            @if ($pen->decision_note)
                                <p class="mt-xs font-caption text-caption text-on-surface-variant">
                                    <span class="font-semibold">Kết luận ({{ $pen->decider?->name }}):</span> {{ $pen->decision_note }}
                                </p>
                            @endif
                        </td>
                        <td class="font-code text-code">{{ $pen->violation_date->format('d/m/Y') }}</td>
                        <td>
                            @if ((float) $pen->amount > 0)
                                <x-ui.money :value="-$pen->amount" suffix="đ" />
                                @if (in_array($pen->status, ['pending', 'explained', 'confirmed'], true))
                                    <span class="block text-right font-caption text-caption text-on-surface-variant">đề xuất</span>
                                @endif
                            @else
                                <span class="block text-right text-on-surface-variant">—</span>
                            @endif
                        </td>
                        <td class="font-code text-code">
                            {{ $pen->due_date?->format('d/m/Y') ?? '—' }}
                            @if ($pen->paid_at)
                                <span class="block font-caption text-caption text-tertiary">Nộp {{ $pen->paid_at->format('d/m/Y') }}</span>
                            @endif
                        </td>
                        <td>
                            <span class="inline-block rounded-full border px-2.5 py-1 text-[10px] font-bold {{ $pen->status_badge }}">{{ $pen->status_label }}</span>
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap justify-end gap-xs">
                                @if ($canExplain)
                                    <x-ui.button size="sm" icon="edit_note" @click="$dispatch('open-modal', 'explain-{{ $pen->id }}')">Giải trình</x-ui.button>
                                @endif
                                @if ($canDecide)
                                    <x-ui.button size="sm" icon="gavel" @click="$dispatch('open-modal', 'decide-{{ $pen->id }}')">Chốt biên bản</x-ui.button>
                                @endif
                                @can('violation.mark_paid')
                                    @if ($pen->status === 'fined')
                                        <form action="{{ route('penalties.mark-paid', $pen->id) }}" method="POST">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="secondary" icon="payments">Đã nộp phạt</x-ui.button>
                                        </form>
                                    @endif
                                @endcan
                                @can('violation.mark_resolved')
                                    @if (in_array($pen->status, \App\Models\Penalty::OPEN_STATUSES, true))
                                        <form action="{{ route('penalties.resolve', $pen->id) }}" method="POST" data-confirm="Đóng vụ, miễn phạt tiền biên bản {{ $pen->code }}?">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="ghost">Đóng vụ</x-ui.button>
                                        </form>
                                    @endif
                                @endcan
                                @can('violation.cancel')
                                    @if (in_array($pen->status, ['pending', 'explained', 'confirmed'], true))
                                        <form action="{{ route('penalties.cancel', $pen->id) }}" method="POST" data-confirm="Hủy biên bản {{ $pen->code }}?">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="danger-text">Hủy</x-ui.button>
                                        </form>
                                    @endif
                                @endcan
                            </div>

                            @if ($canExplain)
                                <x-ui.modal name="explain-{{ $pen->id }}" title="Giải trình biên bản {{ $pen->code }}" class="text-left">
                                    <form id="explain-form-{{ $pen->id }}" action="{{ route('penalties.explain', $pen->id) }}" method="POST" class="space-y-md">
                                        @csrf
                                        <p>Lỗi: <strong>{{ $pen->violation_type }}</strong> ngày {{ $pen->violation_date->format('d/m/Y') }}.</p>
                                        <x-ui.textarea name="explanation" label="Nội dung giải trình" required rows="4" placeholder="Trình bày lý do, hoàn cảnh..." />
                                    </form>
                                    <x-slot:footer>
                                        <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'explain-{{ $pen->id }}')">Hủy</x-ui.button>
                                        <x-ui.button type="submit" form="explain-form-{{ $pen->id }}" icon="send">Gửi giải trình</x-ui.button>
                                    </x-slot:footer>
                                </x-ui.modal>
                            @endif

                            @if ($canDecide)
                                <x-ui.modal name="decide-{{ $pen->id }}" title="Chốt biên bản {{ $pen->code }}" class="text-left">
                                    <form id="decide-form-{{ $pen->id }}" action="{{ route('penalties.confirm', $pen->id) }}" method="POST" class="space-y-md"
                                          x-data="{ decision: 'fine' }">
                                        @csrf
                                        <div class="rounded-lg bg-surface-container-low p-sm font-body-small text-body-small">
                                            <p><strong>{{ $pen->user?->name }}</strong> — {{ $pen->violation_type }} ({{ $pen->category_label }})</p>
                                            <p class="mt-xs">{{ $pen->explanation ? 'Giải trình: '.$pen->explanation : 'Nhân sự chưa gửi giải trình.' }}</p>
                                        </div>
                                        <x-ui.select name="decision" label="Kết luận" required x-model="decision"
                                                     :options="['fine' => 'Quyết phạt tiền (nộp trong 2 ngày, quá hạn trừ lương)', 'error' => 'Xác nhận lỗi, chưa phạt tiền']" />
                                        <div x-show="decision === 'fine'">
                                            <x-ui.input type="number" name="amount" label="Số tiền phạt (VNĐ)" min="1000" step="1000"
                                                        :value="(float) $pen->amount > 0 ? (int) $pen->amount : null" />
                                        </div>
                                        <x-ui.textarea name="decision_note" label="Ghi chú kết luận" rows="2" />
                                    </form>
                                    <x-slot:footer>
                                        <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'decide-{{ $pen->id }}')">Hủy</x-ui.button>
                                        <x-ui.button type="submit" form="decide-form-{{ $pen->id }}" icon="gavel">Chốt</x-ui.button>
                                    </x-slot:footer>
                                </x-ui.modal>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <x-ui.empty-state icon="gavel" title="Không có biên bản vi phạm nào"
                                              :description="$canViewAll ? 'Thử đổi từ khoá hoặc xoá bộ lọc.' : 'Bạn không có biên bản vi phạm nào.'" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer><x-ui.pagination :paginator="$penalties" /></x-slot:footer>
    </x-ui.data-table>

    @can('violation.create')
        <x-ui.modal name="new-penalty" title="Ghi nhận vi phạm" :show="$errors->hasAny(['user_id', 'violation_type', 'violation_date'])">
            <form id="new-penalty-form" action="{{ route('penalties.store') }}" method="POST" class="space-y-md"
                  x-data="{ category: @js(old('error_category', 'operations')) }">
                @csrf
                <x-ui.select name="user_id" label="Nhân sự vi phạm" required placeholder="-- Chọn nhân sự --"
                             :options="$users->mapWithKeys(fn ($u) => [$u->id => $u->name.' ('.$u->email.')'])" />
                <x-ui.select name="error_category" label="Loại lỗi" required x-model="category"
                             hint="Lỗi chuyên môn do Học thuật (HT) chốt; lỗi vận hành do Học vụ / Quản lý (CM) chốt."
                             :options="collect(\App\Models\Penalty::CATEGORIES)->map(fn ($c) => $c['label'].' — '.$c['confirmer'])->all()" />
                <x-ui.field label="Lỗi vi phạm" name="violation_type" required for="f_violation_type">
                    <input list="violation-types" id="f_violation_type" name="violation_type" required value="{{ old('violation_type') }}"
                           placeholder="Chọn lỗi thường gặp hoặc nhập mô tả"
                           class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
                    <datalist id="violation-types">
                        @foreach (\App\Models\Penalty::COMMON_VIOLATIONS as $types)
                            @foreach ($types as $type)
                                <option value="{{ $type }}"></option>
                            @endforeach
                        @endforeach
                    </datalist>
                </x-ui.field>
                <div class="grid grid-cols-2 gap-md">
                    <x-ui.date name="violation_date" label="Ngày vi phạm" required :value="old('violation_date', date('Y-m-d'))" />
                    <x-ui.select name="class_id" label="Lớp liên quan" placeholder="— Không —"
                                 :options="$classes->pluck('name', 'id')" />
                </div>
                <x-ui.textarea name="notes" label="Mô tả sự việc" rows="2" />
                <p class="font-caption text-caption text-on-surface-variant">
                    Chưa cần nhập số tiền: mức phạt do HT/CM chốt sau khi nhân sự giải trình.
                </p>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'new-penalty')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="new-penalty-form" icon="save">Ghi nhận</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan
</x-app-layout>
