<x-app-layout>
    @include('crm.partials.header-tabs')

    <div class="space-y-4">
        <x-ui.page-header title="Khách chốt — Xác nhận chính thức"
            description="Học vụ / Quản lý cơ sở kiểm tra hồ sơ nhập học của học viên vừa xếp lớp: đã gửi tài khoản, đã vào nhóm Zalo lớp, đã nhận giáo trình — rồi xác nhận học viên chính thức." />

        <x-ui.tabs>
            <x-ui.tab :href="route('crm.confirmations')" :active="$status === 'pending'" :count="$pendingCount">Chờ xác nhận</x-ui.tab>
            <x-ui.tab :href="route('crm.confirmations', ['status' => 'confirmed'])" :active="$status === 'confirmed'">Đã xác nhận</x-ui.tab>
        </x-ui.tabs>

        <x-ui.filter-bar placeholder="Tìm tên, mã học viên, SĐT...">
            <input type="hidden" name="status" value="{{ $status }}">
        </x-ui.filter-bar>

        <x-ui.data-table min-width="1080px">
            <table>
                <thead>
                    <tr>
                        <th>Học viên</th>
                        <th>Lớp học</th>
                        <th>Ngày xếp lớp</th>
                        <th>Sales phụ trách</th>
                        <th>Hồ sơ nhập học</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($enrollments as $enrollment)
                        <tr class="align-top" id="enrollment-{{ $enrollment->id }}">
                            <td>
                                <a href="{{ route('crm.customers.show', $enrollment->customer_id) }}" class="font-semibold hover:text-primary">{{ $enrollment->student?->name }}</a>
                                <div class="font-code text-caption text-on-surface-variant">{{ $enrollment->student?->code }} · {{ $enrollment->student?->phone }}</div>
                                @if ($enrollment->student)
                                    <x-ui.badge class="mt-xs">{{ $enrollment->student->status_label }}</x-ui.badge>
                                @endif
                            </td>
                            <td>
                                <div class="font-semibold">{{ $enrollment->classModel?->name ?? '—' }}</div>
                                <div class="text-caption text-on-surface-variant">
                                    {{ $enrollment->classModel?->branch?->name }}
                                    · {{ $enrollment->classModel?->status === 'upcoming' ? 'Sắp khai giảng'.($enrollment->classModel?->start_date ? ' '.$enrollment->classModel->start_date->format('d/m/Y') : '') : 'Đang học' }}
                                </div>
                            </td>
                            <td class="font-code">{{ $enrollment->enrolled_at?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $enrollment->customer?->assignedUser?->name ?? '—' }}</td>
                            @if ($enrollment->confirmed_at)
                                <td>
                                    <x-ui.badge color="success">Đã xác nhận chính thức</x-ui.badge>
                                    <div class="text-caption text-on-surface-variant mt-xs">{{ $enrollment->confirmed_at->format('d/m/Y H:i') }} · {{ $enrollment->confirmedBy?->name }}</div>
                                </td>
                                <td class="text-right text-caption text-on-surface-variant">—</td>
                            @else
                                <td>
                                    <form id="confirm-{{ $enrollment->id }}" method="POST" action="{{ route('crm.enrollments.confirm', $enrollment) }}" class="flex flex-col gap-xs">
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
                                        <x-ui.button type="submit" form="confirm-{{ $enrollment->id }}" name="action" value="confirm" size="sm" icon="verified_user">Xác nhận chính thức</x-ui.button>
                                        <x-ui.button type="submit" form="confirm-{{ $enrollment->id }}" name="action" value="save" size="sm" variant="ghost">Lưu tiến độ</x-ui.button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-ui.empty-state icon="verified_user" :title="$status === 'confirmed' ? 'Chưa có học viên được xác nhận' : 'Không có học viên chờ xác nhận'" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer><x-ui.pagination :paginator="$enrollments" unit="học viên" /></x-slot:footer>
        </x-ui.data-table>
    </div>
</x-app-layout>
