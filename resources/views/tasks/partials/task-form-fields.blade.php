{{-- Trường form "Giao việc mới" (mockup form_giao_vi_c) — dùng chung cho modal (tasks/create, $asModal) và trang tạo.
     Biến: $users, $branches, $classes, $asModal (bool, tuỳ chọn — id tiền tố "modal-task-" tránh trùng bộ lọc ở danh sách). --}}
@php
    $isRecurringOld = old('taskType') === 'recurring';
    $fid = fn (string $field) => ($asModal ?? false) ? 'modal-task-'.$field : 'f_'.$field;
@endphp
<div class="space-y-md" x-data="{ isRecurring: @js($isRecurringOld) }">
    <x-ui.input name="taskTitle" :id="$fid('taskTitle')" label="Tiêu đề công việc" required placeholder="Nhập tiêu đề công việc..." maxlength="255" />
    <x-ui.textarea name="taskDescription" :id="$fid('taskDescription')" label="Mô tả chi tiết" rows="3" placeholder="Mô tả nội dung công việc chi tiết..." />
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
        <x-ui.select name="assignee" :id="$fid('assignee')" label="Người nhận" required placeholder="Chọn nhân sự..."
                     :options="$users->mapWithKeys(fn ($u) => [$u->id => $u->name.' ('.($u->getRoleNames()->map(fn ($r) => \App\Helpers\AclHelper::shortRoleLabel($r))->implode(', ') ?: 'Nhân viên').')'])" />
        <x-ui.input type="date" name="dueDate" :id="$fid('dueDate')" label="Hạn hoàn thành" required :value="old('dueDate', now()->addDays(2)->format('Y-m-d'))" />
    </div>
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
        <x-ui.select name="branch_id" :id="$fid('branch_id')" label="Chi nhánh" placeholder="-- Không chỉ định --" :options="$branches->pluck('name', 'id')" />
        <x-ui.select name="class_id" :id="$fid('class_id')" label="Gắn lớp (nếu có)" placeholder="-- Không gắn lớp --"
                     :options="$classes->mapWithKeys(fn ($c) => [$c->id => $c->name.' ('.$c->code.')'])" />
    </div>
    <fieldset class="space-y-sm rounded-lg border border-outline-variant bg-surface-container-low p-md">
        <legend class="px-xs font-body-small text-body-small font-medium text-on-surface">Loại công việc</legend>
        <div class="flex flex-wrap items-center gap-lg">
            <label class="inline-flex cursor-pointer items-center gap-xs">
                <input type="radio" name="taskType" value="one-time" @checked(! $isRecurringOld) x-on:change="isRecurring = false" class="text-primary-container focus:ring-primary-container">
                <span class="font-body-base text-body-base">Phát sinh</span>
            </label>
            <label class="inline-flex cursor-pointer items-center gap-xs">
                <input type="radio" name="taskType" value="recurring" @checked($isRecurringOld) x-on:change="isRecurring = true" class="text-primary-container focus:ring-primary-container">
                <span class="font-body-base text-body-base">Lặp đi lặp lại</span>
            </label>
        </div>
        <div x-show="isRecurring" x-cloak class="border-t border-outline-variant pt-sm">
            <x-ui.select name="frequency" :id="$fid('frequency')" label="Tần suất" :options="['daily' => 'Hàng ngày', 'weekly' => 'Hàng tuần', 'monthly' => 'Hàng tháng']" :value="old('frequency', 'weekly')"
                         hint="Khi việc lặp được xác nhận hoàn thành, hệ thống tự tạo lượt kế tiếp theo tần suất." />
        </div>
    </fieldset>
</div>
