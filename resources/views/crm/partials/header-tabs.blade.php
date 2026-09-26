{{-- Header chung của workspace CRM: tiêu đề, tìm kiếm, nút thêm/nhập + tab (khai báo ở SidebarMenu, workspace "crm"). --}}
<div class="-mx-md -mt-md mb-lg border-b border-surface-container-highest bg-surface px-md pt-md lg:-mx-lg lg:-mt-lg lg:px-lg">
    <div class="flex flex-col gap-md pb-sm md:flex-row md:items-center md:justify-between">
        <h1 class="font-h1 text-h1 text-on-surface">Quản lý tuyển sinh</h1>

        <div class="flex items-center gap-sm">
            <form method="GET" action="{{ route('crm.customers.index') }}" role="search" class="relative w-64 sm:w-80">
                <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">search</span>
                <input type="search" name="search" value="{{ request()->routeIs('crm.customers.index') ? request('search') : '' }}"
                       placeholder="Tìm kiếm khách hàng..." aria-label="Tìm kiếm khách hàng"
                       class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-10 pr-md font-body-small text-body-small text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
            </form>

            @can('lead.create')
                <x-ui.button variant="secondary" icon="upload_file" :href="route('crm.import')" modal="lg">Nhập Excel</x-ui.button>
                <x-ui.button icon="add" :href="route('crm.customers.create')" modal="2xl">Thêm khách mới</x-ui.button>
            @endcan
        </div>
    </div>

    <x-ui.workspace-tabs workspace="crm" class="!mb-0 !border-b-0" />
</div>
