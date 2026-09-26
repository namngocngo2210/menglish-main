<x-app-layout>
    @include('crm.partials.header-tabs')

    <div class="space-y-4">
        {{-- Trang không có bộ lọc riêng: lọc nhanh đặt trong khung riêng như các tab danh sách khác --}}
        <div class="rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm">
            <x-ui.workspace-chips workspace="crm" />
        </div>
        @include('crm.partials.waiting-class-table')
    </div>
</x-app-layout>
