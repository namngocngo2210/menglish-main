{{--
    Nhập khách từ Excel — bước 1 (tải file). Dùng chung cho trang đầy đủ (crm/import) và modal ($asModal).
    Biến: $branches, $salesUsers, $canAssign, $preview (mảng|null), $asModal (bool, tuỳ chọn).
    Trong modal: form id "modal-crm-import-form", nút gửi ở footer x-ui.modal-frame.
--}}
@php $asModal = $asModal ?? false; @endphp
<form id="{{ $asModal ? 'modal-' : '' }}crm-import-form" method="POST" action="{{ route('crm.import.preview') }}" enctype="multipart/form-data"
      @class([
          'grid grid-cols-1 gap-md',
          'rounded-xl border border-outline-variant bg-surface-container-lowest p-md md:grid-cols-2 lg:grid-cols-4' => ! $asModal,
      ])>
    @csrf
    <x-ui.field label="File khách hàng (.xlsx, .csv)" name="file" :for="$asModal ? 'modal-crm-import-file' : 'f_file'" required>
        <input type="file" name="file" id="{{ $asModal ? 'modal-crm-import-file' : 'f_file' }}" required accept=".xlsx,.xls,.csv"
               class="block w-full rounded-lg border border-outline-variant bg-surface-container-lowest p-xs text-body-small">
    </x-ui.field>
    <x-ui.select name="branch_id" :id="$asModal ? 'modal-crm-import-branch' : null" label="Chi nhánh nhận khách" required placeholder="-- Chọn chi nhánh --"
        :options="$branches->pluck('name', 'id')" :value="$preview['branch_id'] ?? ($branches->count() === 1 ? $branches->first()->id : null)" />
    @if ($canAssign)
        <x-ui.select name="assigned_user_id" :id="$asModal ? 'modal-crm-import-assignee' : null" label="Sales phụ trách" placeholder="-- Tôi phụ trách --"
            :options="$salesUsers->mapWithKeys(fn ($u) => [$u->id => $u->name])" :value="$preview['assigned_user_id'] ?? null" />
    @else
        <x-ui.field label="Sales phụ trách">
            <div class="rounded-lg border border-outline-variant bg-surface-container-low px-md py-sm">{{ auth()->user()->name }}</div>
        </x-ui.field>
    @endif
    <x-ui.input name="default_source" :id="$asModal ? 'modal-crm-import-source' : null" label="Nguồn mặc định" placeholder="VD: Sự kiện Offline" hint="Dùng khi dòng không có cột Nguồn." />
    <div @class(['flex items-center justify-between gap-md', 'md:col-span-2 lg:col-span-4' => ! $asModal])>
        <p class="text-caption text-on-surface-variant">Cột nhận diện: Họ tên*, Số điện thoại*, Tên phụ huynh, SĐT phụ huynh, Email, Ngày sinh, Giới tính, Địa chỉ, Nguồn, Khóa học quan tâm, Ghi chú. Tối đa 1.000 dòng.</p>
        @unless ($asModal)
            <x-ui.button type="submit" icon="fact_check">Kiểm tra dữ liệu</x-ui.button>
        @endunless
    </div>
</form>
