<x-app-layout>
    @include('crm.partials.header-tabs')

    {{-- Mockup epic-6/khach-hang-chot-thanh-cong-xac-nhan: tiêu đề + số học viên, thẻ "Chờ xếp lớp" + Gán lớp, lọc Chi nhánh / Lớp học / Tìm kiếm,
         bảng Khách đã có lớp (Trạng thái, Xác nhận chính thức / Đã là học viên) + popup xác nhận.
         A6 Q5: không có trạng thái "Học thử" trên hồ sơ học viên. Checklist hồ sơ nhập học giữ theo Phase 1 (tài khoản, Zalo, giáo trình). --}}
    <div class="flex flex-col gap-lg" x-data="{ confirmForm: null, confirmName: '' }">
        <header>
            <h2 class="flex flex-wrap items-center gap-sm font-h2 text-h2 text-on-surface">
                Khách hàng đã chốt thành công
                <span class="rounded-full bg-primary-container/10 px-md py-xs font-body-small text-body-small font-bold text-primary">{{ number_format($totalCount, 0, ',', '.') }} học viên</span>
            </h2>
            <p class="mt-xs font-body-medium text-body-medium text-on-surface-variant">Quản lý danh sách học viên sau khi hoàn tất thủ tục đăng ký và phân bổ lớp học. Học vụ / Quản lý cơ sở kiểm tra hồ sơ nhập học rồi xác nhận học viên chính thức.</p>
        </header>

        {{-- 1. Chờ xếp lớp (Cần xử lý gấp) --}}
        @if ($waitingLeads->isNotEmpty())
            <section class="rounded-xl border border-error/20 bg-error-container/20 p-lg">
                <div class="mb-md flex items-center gap-sm">
                    <span class="material-symbols-outlined text-error" style="font-variation-settings: 'FILL' 1;">warning</span>
                    <h2 class="font-h3 text-h3 text-on-surface">Chờ xếp lớp (Cần xử lý gấp)</h2>
                    <span class="rounded-full bg-error px-sm py-0.5 font-code text-caption font-bold text-white">{{ str_pad((string) $waitingLeads->count(), 2, '0', STR_PAD_LEFT) }}</span>
                </div>
                <div class="grid grid-cols-1 gap-md md:grid-cols-2 xl:grid-cols-4">
                    @foreach ($waitingLeads as $lead)
                        @php $matches = $matchingClassesByLead->get($lead->id, collect()); @endphp
                        <div class="flex flex-col gap-md rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm">
                            <div class="flex items-center gap-sm">
                                <x-ui.avatar :name="$lead->name" />
                                <div class="min-w-0">
                                    <h3 class="truncate font-body-semibold text-body-semibold text-on-surface"><a href="{{ route('crm.customers.show', $lead->id) }}" class="hover:text-primary">{{ $lead->name }}</a></h3>
                                    <p class="font-code text-caption text-on-surface-variant">{{ $lead->phone }}</p>
                                </div>
                            </div>
                            <div class="space-y-xs font-body-small text-body-small">
                                <div class="flex justify-between gap-sm"><span class="text-on-surface-variant">Chi nhánh:</span><span class="text-right font-medium text-on-surface">{{ $lead->waitingBranch?->name ?? $lead->branch?->name ?? '—' }}</span></div>
                                <div class="flex justify-between gap-sm"><span class="text-on-surface-variant">Ngày chốt:</span><span class="font-code text-on-surface">{{ $lead->converted_at?->format('d/m/Y H:i') ?? '—' }}</span></div>
                            </div>
                            @can('student.assign_class')
                                @if ($matches->isNotEmpty())
                                    <form action="{{ route('crm.customers.assign-class', $lead->id) }}" method="POST" class="mt-auto flex flex-col gap-sm">
                                        @csrf
                                        <x-ui.select name="class_id" value="" required aria-label="Lớp gán cho {{ $lead->name }}" class="font-body-small text-body-small">
                                            @foreach ($matches as $class)
                                                <option value="{{ $class->id }}">{{ $class->name }} · còn {{ $class->max_capacity > 0 ? max(0, $class->max_capacity - $class->active_enrollments_count) : '∞' }} chỗ</option>
                                            @endforeach
                                        </x-ui.select>
                                        <x-ui.button type="submit" size="sm" icon="group_add" class="w-full">Gán lớp</x-ui.button>
                                    </form>
                                @else
                                    <p class="mt-auto font-body-small text-body-small font-semibold text-warning">Chưa có lớp phù hợp</p>
                                @endif
                            @endcan
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- 2. Khách đã có lớp --}}
        <section class="flex flex-col gap-md">
            <x-ui.tabs>
                <x-ui.tab :href="route('crm.confirmations', request()->except(['status', 'page']))" :active="$status === 'pending'" :count="$pendingCount">Chờ xác nhận</x-ui.tab>
                <x-ui.tab :href="route('crm.confirmations', ['status' => 'confirmed'] + request()->except(['status', 'page']))" :active="$status === 'confirmed'">Đã xác nhận</x-ui.tab>
            </x-ui.tabs>

            <x-ui.filter-bar placeholder="Tìm kiếm học viên..." class="!mb-0">
                <input type="hidden" name="status" value="{{ $status }}">
                <x-ui.select name="branch_id" inline-label="Chi nhánh:" :options="$filterBranches->pluck('name', 'id')" placeholder="Tất cả chi nhánh" aria-label="Chi nhánh" />
                <x-ui.select name="class_id" inline-label="Lớp học:" :options="$filterClasses->pluck('name', 'id')" placeholder="Tất cả lớp" aria-label="Lớp học" />
            </x-ui.filter-bar>

            <x-ui.data-table min-width="1180px">
                <x-slot:header>
                    <h2 class="font-h3 text-h3 text-on-surface">Khách đã có lớp <span class="font-body-medium text-body-medium text-on-surface-variant">({{ $enrollments->total() }})</span></h2>
                </x-slot:header>
                <table>
                    <thead>
                        <tr>
                            <th>Họ tên</th>
                            <th>Số điện thoại</th>
                            <th>Chi nhánh</th>
                            <th>Lớp học</th>
                            <th>Ngày chốt</th>
                            <th>Trạng thái</th>
                            <th>Hồ sơ nhập học</th>
                            <th class="text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($enrollments as $enrollment)
                            <tr class="align-top" id="enrollment-{{ $enrollment->id }}">
                                <td>
                                    <div class="flex items-center gap-sm">
                                        <x-ui.avatar :name="$enrollment->student?->name ?? '?'" size="sm" />
                                        <div>
                                            <a href="{{ route('crm.customers.show', $enrollment->customer_id) }}" class="font-body-medium text-body-medium text-on-surface hover:text-primary">{{ $enrollment->student?->name }}</a>
                                            <div class="font-code text-caption text-on-surface-variant">{{ $enrollment->student?->code }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ $enrollment->student?->phone ?? $enrollment->customer?->phone }}</td>
                                <td class="whitespace-nowrap text-on-surface-variant">{{ $enrollment->classModel?->branch?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap">
                                    <div class="font-body-medium text-body-medium text-on-surface">{{ $enrollment->classModel?->name ?? '—' }}</div>
                                    <div class="font-code text-caption text-on-surface-variant">Lớp ID: {{ $enrollment->classModel?->code ?? '—' }}
                                        · {{ $enrollment->classModel?->status === 'upcoming' ? 'Sắp khai giảng'.($enrollment->classModel?->start_date ? ' '.$enrollment->classModel->start_date->format('d/m/Y') : '') : 'Đã khai giảng' }}</div>
                                </td>
                                <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ ($enrollment->customer?->converted_at ?? $enrollment->enrolled_at)?->format('d/m/Y') ?? '—' }}</td>
                                <td class="whitespace-nowrap">
                                    @if ($enrollment->student)
                                        <x-ui.badge :color="$enrollment->student->status === 'studying' ? 'success' : 'warning'" pill>{{ $enrollment->student->status_label }}</x-ui.badge>
                                    @endif
                                </td>
                                @if ($enrollment->confirmed_at)
                                    <td>
                                        <x-ui.badge color="success">Đã xác nhận chính thức</x-ui.badge>
                                        <div class="mt-xs font-caption text-caption text-on-surface-variant">{{ $enrollment->confirmed_at->format('d/m/Y H:i') }} · {{ $enrollment->confirmedBy?->name }}</div>
                                    </td>
                                    <td class="text-right">
                                        <span class="inline-flex items-center gap-xs font-body-small text-body-small font-semibold text-tertiary"><span class="material-symbols-outlined text-[18px]">check_circle</span>Đã là học viên</span>
                                    </td>
                                @else
                                    <td>
                                        <form id="confirm-{{ $enrollment->id }}" method="POST" action="{{ route('crm.enrollments.confirm', $enrollment) }}" class="flex flex-col gap-xs font-body-small text-body-small">
                                            @csrf
                                            @foreach (\App\Models\ClassEnrollment::CONFIRMATION_CHECKLIST as $field => $label)
                                                <label class="flex items-center gap-sm">
                                                    <input type="hidden" name="{{ $field }}" value="0">
                                                    <input type="checkbox" name="{{ $field }}" value="1" @checked($enrollment->{$field}) class="rounded border-outline-variant text-primary-container">
                                                    <span>{{ $label }}</span>
                                                </label>
                                            @endforeach
                                        </form>
                                    </td>
                                    <td class="text-right">
                                        <div class="flex flex-col items-end gap-xs">
                                            {{-- Thẻ <button> thường: @js không biên dịch trong thuộc tính của Blade component. --}}
                                            <button type="button"
                                                    @click="confirmForm = @js('confirm-'.$enrollment->id); confirmName = @js($enrollment->student?->name ?? ''); $dispatch('open-modal', 'confirm-official')"
                                                    class="inline-flex items-center gap-xs rounded-lg bg-primary-container px-sm py-xs font-body-medium text-body-small text-white shadow-sm hover:bg-primary">
                                                <span class="material-symbols-outlined text-[16px]">verified_user</span>Xác nhận chính thức
                                            </button>
                                            <x-ui.button type="submit" form="confirm-{{ $enrollment->id }}" name="action" value="save" size="sm" variant="ghost">Lưu tiến độ</x-ui.button>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="8"><x-ui.empty-state icon="verified_user" :title="$status === 'confirmed' ? 'Chưa có học viên được xác nhận' : 'Không có học viên chờ xác nhận'" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                <x-slot:footer><x-ui.pagination :paginator="$enrollments" unit="học viên" /></x-slot:footer>
            </x-ui.data-table>
        </section>

        {{-- Popup xác nhận (mockup) --}}
        <x-ui.modal name="confirm-official" title="Xác nhận học viên" max-width="md">
            <div class="flex items-start gap-md">
                <span class="material-symbols-outlined rounded-full bg-secondary-fixed p-sm text-secondary">info</span>
                <p class="font-body-base text-body-base text-on-surface-variant">Xác nhận học viên <strong class="text-on-surface" x-text="confirmName"></strong> đã chính thức bắt đầu học? Cần tick đủ hồ sơ nhập học (tài khoản, nhóm Zalo, giáo trình).</p>
            </div>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'confirm-official')">Hủy</x-ui.button>
                <x-ui.button type="submit" name="action" value="confirm" x-bind:form="confirmForm" icon="verified_user">Xác nhận chính thức</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-app-layout>
