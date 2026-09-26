{{--
    Trường cơ bản của vai trò (tên hiển thị / mã / mô tả) — dùng chung cho trang đầy đủ (roles/form, kèm ma trận quyền) và modal.
    Biến: $role, $isSystemRole, $asModal (bool, tuỳ chọn — id tiền tố "modal-").
--}}
@php $id = fn (string $field) => ($asModal ?? false) ? 'modal-role-'.$field : null; @endphp
<x-ui.input name="label" :id="$id('label')" label="Tên hiển thị" :value="$role->label ?? ($role->exists ? \App\Helpers\AclHelper::shortRoleLabel($role->name) : '')" placeholder="Ví dụ: Thu ngân chi nhánh" />
<x-ui.input name="name" :id="$id('name')" label="Mã vai trò" :value="$role->name" required placeholder="vd. cashier_branch" hint="Chữ thường, số, gạch dưới. Vai trò hệ thống không đổi mã."
            :readonly="$isSystemRole" />
<x-ui.input name="description" :id="$id('description')" label="Mô tả" :value="$role->description" placeholder="Vai trò này dùng cho ai, làm gì" />
