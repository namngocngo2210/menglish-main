{{--
    Form Sửa permission — dùng chung cho trang đầy đủ (permissions/form) và modal ($asModal).
    Biến: $permission, $asModal (bool, tuỳ chọn). Trong modal: id tiền tố "modal-", nút Lưu ở footer x-ui.modal-frame.
--}}
@php $asModal = $asModal ?? false; @endphp
<form id="{{ $asModal ? 'modal-' : '' }}permission-form" method="POST" class="space-y-md"
      action="{{ $permission->exists ? route('permissions.update', $permission) : route('permissions.store') }}">
    @csrf
    @if ($permission->exists) @method('PUT') @endif

    <x-ui.input name="name" :id="$asModal ? 'modal-permission-name' : null" label="Tên permission (module.action)" required
                :value="$permission->name" placeholder="vd: report.export" class="font-code" hint="Chữ thường, gạch dưới, dạng module.action." />

    @unless ($asModal)
        <div class="flex justify-end gap-sm border-t border-surface-container pt-md">
            <x-ui.button variant="secondary" :href="route('permissions.index')">Hủy</x-ui.button>
            <x-ui.button type="submit" icon="save">Lưu permission</x-ui.button>
        </div>
    @endunless
</form>
