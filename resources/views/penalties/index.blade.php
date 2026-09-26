{{-- Mockup: ui-full-tinh-nang-menglish/epic-8-danh-sach-phat --}}
<x-app-layout>
    @include('partials.data-confirm')
    @php
        $stepColors = [
            'recorded' => 'warning', 'confirmed' => 'error', 'fined' => 'primary', 'paid' => 'success',
            'remedied' => 'info', 'resolved' => 'secondary', 'cancelled' => 'neutral',
        ];
        $currentStep = request('step');
        $isLockedDate = fn ($date) => $lockedRanges->contains(fn ($p) => $date->between(\Illuminate\Support\Carbon::parse($p->start_date)->startOfDay(), \Illuminate\Support\Carbon::parse($p->end_date)->endOfDay()));
        $advancedOpen = request()->hasAny(['category', 'status', 'from', 'to']) && collect(request()->only(['category', 'status', 'from', 'to']))->filter()->isNotEmpty();
    @endphp

    <x-ui.page-header title="Danh sách vi phạm"
                      description="Quản lý và theo dõi các bước xử lý vi phạm nhân sự tại MEnglish: ghi nhận → nhân sự giải trình → HT/CM chốt lỗi, chốt mức phạt → nộp trong 2 ngày (quá hạn trừ lương) → khắc phục.">
        <x-slot:actions>
            @can('violation.create')
                <x-ui.button icon="add_circle" x-on:click="$dispatch('open-modal', 'new-penalty')">Ghi nhận vi phạm mới</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('locked_penalty'))
        <x-ui.alert type="error" dismissible class="mb-md" :title="\Illuminate\Support\Str::before(session('locked_penalty'), ' Vui lòng')">
            Vui lòng liên hệ bộ phận Kế toán để được hỗ trợ mở khóa kỳ lương nếu cần thiết.
        </x-ui.alert>
    @elseif ($errors->any())
        <x-ui.alert type="error" class="mb-md">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="mb-lg grid grid-cols-2 gap-md lg:grid-cols-4">
        <x-ui.stat-card label="Chờ giải trình" :value="$counts['pending'] ?? 0" icon="edit_note" tone="warning" />
        <x-ui.stat-card label="Chờ HT/CM chốt" :value="($counts['explained'] ?? 0) + ($counts['confirmed'] ?? 0)" icon="gavel" tone="secondary" />
        <x-ui.stat-card label="Đã chốt phạt (trong hạn nộp)" :value="max(0, ($counts['fined'] ?? 0) - $overdueCount)" icon="schedule" tone="primary" />
        <x-ui.stat-card label="Quá hạn — sẽ trừ lương" :value="$overdueCount" icon="money_off" tone="error" />
    </div>

    <form method="GET" action="{{ route('penalties.index') }}" role="search" x-data="{ advanced: @js($advancedOpen) }"
          class="mb-lg space-y-md rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm">
        @if ($currentStep)<input type="hidden" name="step" value="{{ $currentStep }}">@endif
        <div class="flex flex-col gap-md lg:flex-row lg:items-end">
            <div class="min-w-[260px] flex-1">
                <x-ui.input type="search" name="search" label="Tìm kiếm nhân viên" icon="search" :value="request('search')" placeholder="Nhập tên hoặc mã nhân viên..." />
            </div>
            <div class="flex flex-col gap-xs">
                <span class="font-label text-label uppercase tracking-wide text-on-surface-variant">Lọc theo bước</span>
                <div class="flex flex-wrap gap-xs">
                    @foreach (['' => 'Tất cả'] + \App\Models\Penalty::STEPS as $key => $label)
                        @php $active = (string) $currentStep === (string) $key; @endphp
                        <a href="{{ request()->fullUrlWithQuery(['step' => $key ?: null, 'page' => null]) }}"
                           class="rounded-full border px-md py-xs font-body-small text-body-small transition-colors {{ $active ? 'border-primary-container bg-primary-container text-white' : 'border-outline-variant bg-surface-container-lowest text-on-surface-variant hover:border-primary-container hover:text-primary' }}">{{ $label }}</a>
                    @endforeach
                </div>
            </div>
            <x-ui.button variant="secondary" icon="filter_list" x-on:click="advanced = ! advanced">Bộ lọc nâng cao</x-ui.button>
        </div>
        <div x-show="advanced" x-cloak class="flex flex-wrap items-end gap-md border-t border-surface-container pt-md">
            <x-ui.select name="category" placeholder="Tất cả loại lỗi" inline-label="Loại lỗi:"
                         :options="collect(\App\Models\Penalty::CATEGORIES)->map(fn ($c) => $c['label'])->all()" />
            <x-ui.select name="status" placeholder="Tất cả trạng thái" inline-label="Trạng thái:"
                         :options="['open' => 'Đang xử lý (chưa đóng)', 'overdue' => 'Quá hạn nộp'] + \App\Models\Penalty::statusLabels()" />
            <x-ui.date name="from" inline-label="Từ ngày:" :value="request('from')" />
            <x-ui.date name="to" inline-label="Đến ngày:" :value="request('to')" />
        </div>
        <div class="flex justify-end gap-sm">
            @if (collect(request()->except(['page', 'per_page']))->filter()->isNotEmpty())
                <x-ui.button variant="ghost" :href="route('penalties.index')">Xoá lọc</x-ui.button>
            @endif
            <x-ui.button type="submit" icon="search">Lọc</x-ui.button>
        </div>
    </form>

    <x-ui.data-table min-width="1100px">
        <table>
            <thead>
                <tr>
                    <th>Nhân viên</th>
                    <th>Ngày vi phạm</th>
                    <th>Nguồn</th>
                    <th>Lỗi vi phạm</th>
                    <th>Bước hiện tại</th>
                    <th class="text-right">Số tiền phạt</th>
                    <th>Trạng thái GV</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($penalties as $pen)
                    @php
                        $canDecide = auth()->user()->can('violation.confirm_fine') && $pen->canBeDecidedBy(auth()->user())
                            && in_array($pen->status, ['pending', 'explained', 'confirmed'], true);
                        $canExplain = $pen->user_id === auth()->id() && $pen->status === 'pending';
                        $locked = $isLockedDate($pen->violation_date);
                        [$employeeState, $employeeColor] = $pen->employee_state;
                    @endphp
                    <tr class="align-top">
                        <td>
                            <p class="font-semibold text-on-surface">{{ $pen->user?->name }}</p>
                            <p class="font-caption text-caption text-on-surface-variant">ID: {{ $pen->user?->employee_code ?: '—' }} · <span class="font-code">{{ $pen->code }}</span></p>
                        </td>
                        <td class="font-code text-code">{{ $pen->violation_date->format('d/m/Y') }}</td>
                        <td>{{ $pen->source_label }}</td>
                        <td class="max-w-xs">
                            <p class="font-medium text-error">{{ $pen->violation_type }}</p>
                            <p class="font-caption text-caption text-on-surface-variant">{{ $pen->category_label }} · chốt bởi {{ $pen->confirmer_label }}</p>
                        </td>
                        <td>
                            <x-ui.badge :color="$stepColors[$pen->step] ?? 'neutral'">{{ $pen->step_label }}</x-ui.badge>
                            @if ($pen->isOverdue())
                                <span class="mt-xs block font-caption text-caption font-semibold text-error">Quá hạn nộp — sẽ trừ lương</span>
                            @elseif ($pen->status === 'deducted')
                                <span class="mt-xs block font-caption text-caption text-on-surface-variant">Đã trừ vào bảng lương</span>
                            @endif
                        </td>
                        <td class="text-right">
                            @if ((float) $pen->amount > 0 && ! in_array($pen->status, ['pending', 'explained', 'confirmed', 'resolved'], true))
                                <x-ui.money :value="$pen->amount" suffix="đ" />
                                @if ($pen->due_date && $pen->status === 'fined')
                                    <span class="block font-caption text-caption text-on-surface-variant">Hạn nộp {{ $pen->due_date->format('d/m/Y') }}</span>
                                @elseif ($pen->paid_at)
                                    <span class="block font-caption text-caption text-tertiary">Nộp {{ $pen->paid_at->format('d/m/Y') }}</span>
                                @endif
                            @elseif ($pen->status === 'resolved')
                                <span class="font-mono">0đ</span>
                            @else
                                <span class="text-on-surface-variant">---</span>
                            @endif
                        </td>
                        <td>
                            <x-ui.badge :color="$employeeColor">{{ $employeeState }}</x-ui.badge>
                            @if (in_array($pen->status, ['resolved', 'cancelled'], true) || $pen->remedied_at)
                                <span class="mt-xs block font-caption text-caption text-on-surface-variant">Đã kết thúc</span>
                            @endif
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap justify-end gap-xs">
                                @if ($canExplain)
                                    <x-ui.button size="sm" icon="edit_note" x-on:click="$dispatch('open-modal', 'explain-{{ $pen->id }}')">Giải trình</x-ui.button>
                                @endif
                                @if ($canDecide && $pen->step === 'recorded')
                                    <x-ui.button size="sm" icon="gavel" x-on:click="$dispatch('open-modal', 'decide-{{ $pen->id }}')">Chốt lỗi</x-ui.button>
                                @endif
                                @if ($canDecide && $pen->status === 'confirmed')
                                    @if ($locked)
                                        <x-ui.button size="sm" icon="lock" disabled title="Kỳ lương của nhân viên đã khóa — không thể chốt mức phạt">Chốt mức phạt</x-ui.button>
                                    @else
                                        <x-ui.button size="sm" icon="payments" x-on:click="$dispatch('open-modal', 'decide-{{ $pen->id }}')">Chốt mức phạt</x-ui.button>
                                    @endif
                                @endif
                                @can('violation.mark_resolved')
                                    @if (in_array($pen->status, ['pending', 'explained', 'confirmed'], true))
                                        <form action="{{ route('penalties.resolve', $pen->id) }}" method="POST" data-confirm="Đóng biên bản {{ $pen->code }} — không phạt tiền?">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="secondary">Đóng - không phạt</x-ui.button>
                                        </form>
                                    @endif
                                @endcan
                                @can('violation.cancel')
                                    @if (in_array($pen->status, ['pending', 'explained', 'confirmed'], true))
                                        <form action="{{ route('penalties.cancel', $pen->id) }}" method="POST" data-confirm="Hủy biên bản {{ $pen->code }}?">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="danger-text">Hủy vi phạm</x-ui.button>
                                        </form>
                                    @endif
                                @endcan
                                @can('violation.mark_paid')
                                    @if ($pen->status === 'fined')
                                        <form action="{{ route('penalties.mark-paid', $pen->id) }}" method="POST">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="secondary" icon="payments">Đánh dấu đã nộp</x-ui.button>
                                        </form>
                                    @endif
                                @endcan
                                @can('violation.mark_resolved')
                                    @if ($pen->step === 'paid')
                                        <x-ui.button size="sm" variant="secondary" icon="build" x-on:click="$dispatch('open-modal', 'remedy-{{ $pen->id }}')">Ghi nhận khắc phục</x-ui.button>
                                    @endif
                                @endcan
                                <x-ui.button size="sm" variant="ghost" icon="visibility" aria-label="Xem chi tiết {{ $pen->code }}" @click="$dispatch('open-modal', 'view-{{ $pen->id }}')" />
                            </div>

                            <x-ui.modal name="view-{{ $pen->id }}" title="Biên bản {{ $pen->code }}" class="text-left">
                                <dl class="grid grid-cols-3 gap-sm font-body-small text-body-small">
                                    <dt class="text-on-surface-variant">Nhân viên</dt><dd class="col-span-2">{{ $pen->user?->name }} ({{ $pen->user?->employee_code ?: 'chưa có mã' }})</dd>
                                    <dt class="text-on-surface-variant">Lỗi vi phạm</dt><dd class="col-span-2">{{ $pen->violation_type }} — {{ $pen->category_label }}</dd>
                                    <dt class="text-on-surface-variant">Lớp liên quan</dt><dd class="col-span-2">{{ $pen->classModel?->name ?? '—' }}</dd>
                                    <dt class="text-on-surface-variant">Lập bởi</dt><dd class="col-span-2">{{ $pen->reporter?->name ?? 'Hệ thống' }} ({{ $pen->source_label }})</dd>
                                    <dt class="text-on-surface-variant">Mô tả</dt><dd class="col-span-2">{{ $pen->notes ?: '—' }}</dd>
                                    <dt class="text-on-surface-variant">Giải trình</dt><dd class="col-span-2">{{ $pen->explanation ?: 'Chưa giải trình' }}</dd>
                                    <dt class="text-on-surface-variant">Kết luận</dt><dd class="col-span-2">{{ $pen->decision_note ?: '—' }}@if ($pen->decider) ({{ $pen->decider->name }})@endif</dd>
                                    <dt class="text-on-surface-variant">Trạng thái</dt><dd class="col-span-2">{{ $pen->status_label }}</dd>
                                    @if ($pen->remedied_at)
                                        <dt class="text-on-surface-variant">Khắc phục</dt><dd class="col-span-2">{{ $pen->remedied_at->format('d/m/Y') }} — {{ $pen->remedier?->name }}{{ $pen->remedy_note ? ': '.$pen->remedy_note : '' }}</dd>
                                    @endif
                                </dl>
                            </x-ui.modal>

                            @if ($canExplain)
                                <x-ui.modal name="explain-{{ $pen->id }}" title="Giải trình biên bản {{ $pen->code }}" class="text-left">
                                    <form id="explain-form-{{ $pen->id }}" action="{{ route('penalties.explain', $pen->id) }}" method="POST" class="space-y-md">
                                        @csrf
                                        <p>Lỗi: <strong>{{ $pen->violation_type }}</strong> ngày {{ $pen->violation_date->format('d/m/Y') }}.</p>
                                        <x-ui.textarea name="explanation" label="Nội dung giải trình" required rows="4" placeholder="Trình bày lý do, hoàn cảnh..." />
                                    </form>
                                    <x-slot:footer>
                                        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'explain-{{ $pen->id }}')">Hủy</x-ui.button>
                                        <x-ui.button type="submit" form="explain-form-{{ $pen->id }}" icon="send">Gửi giải trình</x-ui.button>
                                    </x-slot:footer>
                                </x-ui.modal>
                            @endif

                            @if ($canDecide)
                                <x-ui.modal name="decide-{{ $pen->id }}" :title="($pen->status === 'confirmed' ? 'Chốt mức phạt ' : 'Chốt lỗi ').$pen->code" class="text-left">
                                    <form id="decide-form-{{ $pen->id }}" action="{{ route('penalties.confirm', $pen->id) }}" method="POST" class="space-y-md"
                                          x-data="{ decision: @js($pen->status === 'confirmed' ? 'fine' : 'error') }">
                                        @csrf
                                        <div class="rounded-lg bg-surface-container-low p-sm font-body-small text-body-small">
                                            <p><strong>{{ $pen->user?->name }}</strong> — {{ $pen->violation_type }} ({{ $pen->category_label }})</p>
                                            <p class="mt-xs">{{ $pen->explanation ? 'Giải trình: '.$pen->explanation : 'Nhân sự chưa gửi giải trình.' }}</p>
                                        </div>
                                        <x-ui.select name="decision" label="Kết luận" required x-model="decision"
                                                     :options="['error' => 'Chốt lỗi (xác nhận có lỗi, chốt mức phạt sau)', 'fine' => 'Chốt mức phạt (nộp trong 2 ngày, quá hạn trừ lương)']" />
                                        <div x-show="decision === 'fine'">
                                            <x-ui.input type="number" name="amount" label="Số tiền phạt (VNĐ)" min="1000" step="1000"
                                                        :value="(float) $pen->amount > 0 ? (int) $pen->amount : null" />
                                        </div>
                                        <x-ui.textarea name="decision_note" label="Ghi chú kết luận" rows="2" />
                                    </form>
                                    <x-slot:footer>
                                        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'decide-{{ $pen->id }}')">Hủy</x-ui.button>
                                        <x-ui.button type="submit" form="decide-form-{{ $pen->id }}" icon="gavel">Chốt</x-ui.button>
                                    </x-slot:footer>
                                </x-ui.modal>
                            @endif

                            @can('violation.mark_resolved')
                                @if ($pen->step === 'paid')
                                    <x-ui.modal name="remedy-{{ $pen->id }}" title="Ghi nhận khắc phục {{ $pen->code }}" max-width="md" class="text-left">
                                        <form id="remedy-form-{{ $pen->id }}" action="{{ route('penalties.remedy', $pen->id) }}" method="POST" class="space-y-md">
                                            @csrf
                                            <p class="font-body-small text-body-small text-on-surface-variant">{{ $pen->user?->name }} — {{ $pen->violation_type }}</p>
                                            <x-ui.textarea name="remedy_note" label="Nội dung khắc phục" rows="3" placeholder="VD: Đã bổ sung nhận xét, cam kết không tái phạm..." />
                                        </form>
                                        <x-slot:footer>
                                            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'remedy-{{ $pen->id }}')">Hủy</x-ui.button>
                                            <x-ui.button type="submit" form="remedy-form-{{ $pen->id }}" icon="check">Ghi nhận khắc phục</x-ui.button>
                                        </x-slot:footer>
                                    </x-ui.modal>
                                @endif
                            @endcan
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
        <x-ui.modal name="new-penalty" title="Ghi nhận vi phạm mới" :show="$errors->hasAny(['user_id', 'violation_type']) || ($errors->has('violation_date') && ! session('locked_penalty'))">
            <form id="new-penalty-form" action="{{ route('penalties.store') }}" method="POST" class="space-y-md"
                  x-data="{ category: @js(old('error_category', 'operations')) }">
                @csrf
                <x-ui.select name="user_id" label="Nhân sự vi phạm" required placeholder="-- Chọn nhân sự --"
                             :options="$users->mapWithKeys(fn ($u) => [$u->id => $u->name.($u->employee_code ? ' — '.$u->employee_code : '').' ('.$u->email.')'])" />
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
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'new-penalty')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="new-penalty-form" icon="save">Ghi nhận</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endcan
</x-app-layout>
