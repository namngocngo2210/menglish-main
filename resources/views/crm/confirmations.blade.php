<x-app-layout>
    @include('crm.partials.header-tabs')

    {{-- Mockup epic-6/khach-hang-chot-thanh-cong-xac-nhan: tiêu đề + số học viên, băng nhắc "Chờ xếp lớp" (link sang màn Chờ xếp lớp), lọc Chi nhánh / Lớp học / Tìm kiếm,
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

        @if (session('temporary_password'))
            <x-ui.alert type="warning" title="Mật khẩu tạm của học viên — chỉ hiển thị một lần">
                <div>Email đăng nhập: <span class="font-code font-semibold">{{ session('student_account_email') }}</span></div>
                <div>Mật khẩu tạm: <span class="font-code font-semibold">{{ session('temporary_password') }}</span></div>
                <div class="font-caption text-caption">Gửi cho phụ huynh; học viên phải đổi mật khẩu ở lần đăng nhập đầu tiên.</div>
            </x-ui.alert>
        @endif

        {{-- 1. Chờ xếp lớp: chỉ băng nhắc + link, xếp lớp làm ở màn Chờ xếp lớp (không lặp khối xếp lớp ở đây) --}}
        @include('crm.partials.waiting-class-banner')

        {{-- 2. Khách đã có lớp --}}
        <section class="flex flex-col gap-md">
            <x-ui.tabs>
                <x-ui.tab :href="route('crm.confirmations', request()->except(['status', 'page']))" :active="$status === 'pending'" :count="$pendingCount">Chờ xác nhận</x-ui.tab>
                <x-ui.tab :href="route('crm.confirmations', ['status' => 'confirmed'] + request()->except(['status', 'page']))" :active="$status === 'confirmed'">Đã xác nhận</x-ui.tab>
            </x-ui.tabs>

            <x-ui.filter-bar placeholder="Tìm kiếm học viên..." class="!mb-0">
                <input type="hidden" name="status" value="{{ $status }}">
                <x-ui.select name="branch_id" label="Chi nhánh" :options="$filterBranches->pluck('name', 'id')" placeholder="Tất cả chi nhánh" />
                <x-ui.select name="class_id" label="Lớp học" :options="$filterClasses->pluck('name', 'id')" placeholder="Tất cả lớp" />
            </x-ui.filter-bar>

            <x-ui.data-table min-width="960px">
                <x-slot:header>
                    <h2 class="font-h3 text-h3 text-on-surface">Khách đã có lớp <span class="font-body-medium text-body-medium text-on-surface-variant">({{ $enrollments->total() }})</span></h2>
                </x-slot:header>
                <table>
                    <thead>
                        <tr>
                            <th>Họ tên</th>
                            <th>Lớp học</th>
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
                                            <div class="font-code text-caption text-on-surface-variant">{{ $enrollment->student?->phone ?? $enrollment->customer?->phone }}</div>
                                            <div class="font-code text-[10px] text-on-surface-variant/70"><x-ui.code :value="$enrollment->student?->code" /></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="min-w-[180px]">
                                    <div class="font-body-medium text-body-medium text-on-surface">{{ $enrollment->classModel?->name ?? '—' }}</div>
                                    <div class="font-caption text-caption text-on-surface-variant">{{ $enrollment->classModel?->branch?->name ?? '—' }}</div>
                                    <div class="font-code text-caption text-on-surface-variant">Lớp ID: {{ $enrollment->classModel?->code ?? '—' }}
                                        · {{ \App\Services\Students\ClassStartActivation::classHasStarted($enrollment->classModel) || $enrollment->classModel?->status === 'completed' ? 'Đã khai giảng' : 'Sắp khai giảng'.($enrollment->classModel?->start_date ? ' '.$enrollment->classModel->start_date->format('d/m/Y') : '') }}</div>
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($enrollment->student)
                                        <x-ui.badge :color="$enrollment->student->status === 'studying' ? 'success' : 'warning'" pill>{{ $enrollment->student->status_label }}</x-ui.badge>
                                    @endif
                                    <div class="mt-xs font-caption text-caption text-on-surface-variant">Chốt: {{ ($enrollment->customer?->converted_at ?? $enrollment->enrolled_at)?->format('d/m/Y') ?? '—' }}</div>
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
                                        <form id="confirm-{{ $enrollment->id }}" method="POST" action="{{ route('crm.enrollments.confirm', $enrollment) }}" class="flex min-w-[190px] flex-col gap-xs font-body-small text-body-small">
                                            @csrf
                                            @foreach (\App\Models\ClassEnrollment::CONFIRMATION_CHECKLIST as $field => $label)
                                                <label class="flex items-center gap-sm">
                                                    <input type="hidden" name="{{ $field }}" value="0">
                                                    <input type="checkbox" name="{{ $field }}" value="1" @checked($enrollment->{$field}) class="rounded border-outline-variant text-primary-container">
                                                    <span>{{ $label }}</span>
                                                </label>
                                            @endforeach
                                        </form>
                                        @if ($enrollment->student?->user)
                                            <form method="POST" action="{{ route('crm.enrollments.reset-account', $enrollment) }}" class="mt-xs flex flex-wrap items-center gap-xs font-caption text-caption text-on-surface-variant">
                                                @csrf
                                                <span class="flex min-w-0 max-w-[240px] items-center gap-xs">Tài khoản: <span class="truncate font-code" title="{{ $enrollment->student->user->email }}">{{ $enrollment->student->user->email }}</span></span>
                                                <button type="submit" class="font-semibold text-primary hover:underline">Cấp mật khẩu tạm</button>
                                            </form>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <div class="flex flex-col items-end gap-xs">
                                            {{-- Thẻ <button> thường: @js không biên dịch trong thuộc tính của Blade component. --}}
                                            <button type="button"
                                                    @click="confirmForm = @js('confirm-'.$enrollment->id); confirmName = @js($enrollment->student?->name ?? ''); $dispatch('open-modal', 'confirm-official')"
                                                    class="inline-flex items-center gap-xs whitespace-nowrap rounded-lg bg-primary-container px-sm py-xs font-body-medium text-body-small text-white shadow-sm hover:bg-primary">
                                                <span class="material-symbols-outlined text-[16px]">verified_user</span>Xác nhận chính thức
                                            </button>
                                            <x-ui.button type="submit" form="confirm-{{ $enrollment->id }}" name="action" value="save" size="sm" variant="ghost">Lưu tiến độ</x-ui.button>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-ui.empty-state icon="verified_user" :title="$status === 'confirmed' ? 'Chưa có học viên được xác nhận' : 'Không có học viên chờ xác nhận'" /></td></tr>
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
