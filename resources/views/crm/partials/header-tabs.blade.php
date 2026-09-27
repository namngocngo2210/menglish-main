{{-- Header chung của workspace CRM: tiêu đề, nút thêm/nhập + tab (khai báo ở SidebarMenu, workspace "crm").
     Không đặt ô tìm kiếm ở đây: mỗi màn đã có ô tìm trong bộ lọc (tránh hai ô tìm trên một màn). --}}
<div class="-mx-md -mt-md mb-lg border-b border-surface-container-highest bg-surface px-md pt-md lg:-mx-lg lg:-mt-lg lg:px-lg">
    <x-ui.page-header title="Quản lý tuyển sinh" class="!mb-0 pb-sm">
        <x-slot:actions>
            @can('lead.create')
                <x-ui.button variant="secondary" icon="upload_file" :href="route('crm.import')" modal="lg">Nhập Excel</x-ui.button>
                <x-ui.button icon="add" :href="route('crm.customers.create')" modal="2xl">Thêm khách mới</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @php
        // Số trên chip lọc nhanh (theo phạm vi dữ liệu của user).
        $crmVisible = fn () => \App\Models\CrmCustomer::query()->visibleTo(auth()->user());
        $crmStageCounts = $crmVisible()->whereIn('stage', ['waiting_class', 'won', 'lost'])
            ->selectRaw('stage, count(*) as total')->groupBy('stage')->pluck('total', 'stage');
        $crmChipCounts = [
            'sla' => $crmVisible()->staleNew()->count(),
            'waiting_class' => (int) ($crmStageCounts['waiting_class'] ?? 0),
            'won' => (int) ($crmStageCounts['won'] ?? 0),
            'lost' => (int) ($crmStageCounts['lost'] ?? 0),
            'deleted' => $crmVisible()->onlyTrashed()->count(),
        ];
    @endphp
    @php(request()->attributes->set('workspace_chip_counts', $crmChipCounts))
    <x-ui.workspace-tabs workspace="crm" class="!mb-0 !border-b-0" />
</div>
