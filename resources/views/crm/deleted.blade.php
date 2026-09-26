<x-app-layout>
    @include('crm.partials.header-tabs')

    <div class="space-y-4">
        <x-ui.alert type="info">
            Khách bị xóa không còn chiếm số điện thoại: có thể tạo lại khách mới cùng SĐT. Khôi phục sẽ bị chặn nếu SĐT đã thuộc khách khác đang hoạt động.
        </x-ui.alert>

        <x-ui.filter-bar placeholder="Tìm tên, mã KH, SĐT khách đã xóa...">
            <x-slot:quick><x-ui.workspace-chips workspace="crm" /></x-slot:quick>
        </x-ui.filter-bar>

        <x-ui.data-table min-width="900px">
            <x-slot:header>
                <h2 class="font-h3 text-h3 text-on-surface">Khách đã xóa</h2>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Khách hàng</th>
                        <th>Số điện thoại</th>
                        <th>Cơ sở</th>
                        <th>Giai đoạn khi xóa</th>
                        <th>Sales phụ trách</th>
                        <th>Ngày xóa</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($deletedCustomers as $dc)
                        <tr>
                            <td>
                                <div class="font-semibold">{{ $dc->name }}</div>
                                <div class="font-code text-caption text-on-surface-variant">{{ $dc->code }}</div>
                            </td>
                            <td class="font-code">{{ $dc->phone }}</td>
                            <td>{{ $dc->branch?->name ?? '—' }}</td>
                            <td><x-ui.badge :color="'stage-'.$dc->stage">{{ $dc->stage_label }}</x-ui.badge></td>
                            <td>{{ $dc->assignedUser?->name ?? 'Chưa phân công' }}</td>
                            <td class="font-code">{{ $dc->deleted_at?->format('d/m/Y H:i') }}</td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('crm.customers.restore', $dc->id) }}" class="inline">
                                    @csrf
                                    <x-ui.button type="submit" size="sm" variant="secondary" icon="restore_from_trash">Khôi phục</x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-ui.empty-state icon="delete_sweep" title="Không có khách đã xóa" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer><x-ui.pagination :paginator="$deletedCustomers" unit="khách" /></x-slot:footer>
        </x-ui.data-table>
    </div>
</x-app-layout>
