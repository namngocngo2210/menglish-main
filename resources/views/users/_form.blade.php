{{--
    Form Thêm / Sửa nhân sự — dùng chung trang đầy đủ (users/form) và modal 3xl ($asModal: id tiền tố "modal-user-", nút Lưu ở footer).
    3 tab phía trình duyệt trong CÙNG 1 form (Tài khoản / Hồ sơ / Lương): mọi trường vẫn gửi đi dù tab đang ẩn.
    Validate lỗi → tab đầu tiên có lỗi được chọn sẵn (render server, aria-selected="true"); ô required nằm ở tab ẩn mà trình duyệt
    chặn gửi → tự chuyển sang tab chứa ô đó (bắt sự kiện invalid).
    Biến: $user, $branches, $roles, $asModal (bool, tuỳ chọn).
--}}
@php
    $asModal = $asModal ?? false;
    $fid = fn (string $field) => $asModal ? 'modal-user-'.$field : $field;
    $tabs = [
        'account' => ['Tài khoản', 'manage_accounts', ['name', 'employee_code', 'email', 'phone', 'branch_id', 'role', 'concurrent_roles', 'password']],
        'profile' => ['Hồ sơ', 'badge', ['id_card_number', 'emergency_contact', 'hometown', 'current_address', 'graduation_school', 'certificates', 'teaching_level']],
        'salary' => ['Hợp đồng & Lương', 'payments', ['contract_type', 'base_salary', 'hourly_rate', 'contract_start_date', 'contract_end_date', 'contract_file']],
    ];
    $tabHasError = fn (array $fields) => collect($fields)->contains(fn ($f) => $errors->has($f) || $errors->has($f.'.*'));
    $initialTab = collect($tabs)->search(fn ($tab) => $tabHasError($tab[2])) ?: 'account';
    $contractTypes = ['Toàn thời gian' => 'Toàn thời gian (Fulltime)', 'Bán thời gian' => 'Bán thời gian (Parttime)', 'Thử việc' => 'Thử việc', 'Cộng tác viên' => 'Cộng tác viên / Trợ giảng'];
@endphp
<form id="{{ $asModal ? 'modal-user-form' : 'user-form' }}" method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" enctype="multipart/form-data"
      x-data="{ tab: @js($initialTab) }"
      x-init="const panel = location.hash && document.getElementById(location.hash.slice(1))?.closest('[data-tab-panel]'); if (panel && $el.contains(panel)) tab = panel.dataset.tabPanel"
      x-on:invalid.capture="tab = $event.target.closest('[data-tab-panel]')?.dataset.tabPanel ?? tab"
      class="space-y-md">
    @csrf
    @if ($user->exists) @method('PUT') @endif

    <div role="tablist" aria-label="Nhóm thông tin nhân sự" class="no-scrollbar flex items-center gap-lg overflow-x-auto border-b border-surface-container-highest">
        @foreach ($tabs as $key => [$label, $icon, $fields])
            <button type="button" role="tab" id="{{ $fid('tab-'.$key) }}" aria-controls="{{ $fid('panel-'.$key) }}"
                    data-tab="{{ $key }}" aria-selected="{{ $initialTab === $key ? 'true' : 'false' }}"
                    x-bind:aria-selected="(tab === @js($key)).toString()" x-on:click="tab = @js($key)"
                    class="-mb-px inline-flex shrink-0 items-center gap-xs whitespace-nowrap border-b-2 px-sm py-sm font-body-medium text-body-medium transition-colors"
                    x-bind:class="tab === @js($key) ? 'border-primary-container font-semibold text-primary' : 'border-transparent text-on-surface-variant hover:text-primary'">
                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ $icon }}</span>{{ $label }}
                @if ($tabHasError($fields))
                    <span class="h-2 w-2 rounded-full bg-error" title="Tab có lỗi cần sửa"></span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Tab 1: Tài khoản --}}
    <div role="tabpanel" id="{{ $fid('panel-account') }}" aria-labelledby="{{ $fid('tab-account') }}" data-tab-panel="account"
         x-show="tab === 'account'" @if ($initialTab !== 'account') x-cloak @endif class="space-y-md">
        <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
            <x-ui.input name="name" :id="$fid('name')" label="Họ và tên" required :value="$user->name" placeholder="Họ và tên đầy đủ" />
            <x-ui.input name="employee_code" :id="$fid('employee_code')" label="Mã nhân viên" :value="$user->employee_code" placeholder="VD: NV-0012" class="font-code" />
            <x-ui.input name="email" :id="$fid('email')" type="email" label="Email công việc" required :value="$user->email" placeholder="VD: nva@menglish.edu.vn" />
            <x-ui.input name="phone" :id="$fid('phone')" label="Số điện thoại" :value="$user->phone" placeholder="10 số, bắt đầu bằng 0" class="font-code" />
            <x-ui.select name="branch_id" :id="$fid('branch_id')" label="Cơ sở / Chi nhánh" required placeholder="-- Chọn cơ sở --"
                         :value="$user->branch_id" :options="$branches->pluck('name', 'id')" />
            <x-ui.select name="role" :id="$fid('role')" label="Vai trò & Chức vụ" required placeholder="-- Chọn vai trò --"
                         :value="$user->getRoleNames()->first()"
                         :options="$roles->mapWithKeys(fn ($roleName) => [$roleName => \App\Helpers\AclHelper::roleLabel($roleName).' ('.$roleName.')'])" />
        </div>

        {{-- Kiêm nhiệm: vai trò phụ ngoài vai trò chính (chỉ các vai trò người thao tác được phép gán). --}}
        @can('user.assign_role')
            @php
                $currentConcurrent = $user->exists ? $user->getRoleNames()->slice(1)->values()->all() : [];
                $checkedConcurrent = old('concurrent_roles', $currentConcurrent);
            @endphp
            <fieldset class="rounded-lg border border-outline-variant p-md">
                <input type="hidden" name="concurrent_roles_present" value="1">
                <legend class="px-xs font-label text-label uppercase text-on-surface-variant">Vai trò kiêm nhiệm</legend>
                <p class="mb-sm font-caption text-caption text-on-surface-variant">Nhân sự giữ thêm các vai trò này ngoài vai trò chính (ví dụ Học vụ kiêm Trợ giảng). Quyền được cộng dồn.</p>
                <div class="grid grid-cols-1 gap-xs sm:grid-cols-2">
                    @foreach ($roles as $roleName)
                        <label class="flex items-center gap-sm font-body-small text-body-small">
                            <input type="checkbox" name="concurrent_roles[]" value="{{ $roleName }}" @checked(in_array($roleName, (array) $checkedConcurrent, true))
                                   class="rounded border-outline-variant text-primary-container focus:ring-primary-container">
                            <span>{{ \App\Helpers\AclHelper::shortRoleLabel($roleName) }}</span>
                        </label>
                    @endforeach
                </div>
                <x-ui.errors class="mt-1" :messages="array_merge($errors->get('concurrent_roles'), collect($errors->get('concurrent_roles.*'))->flatten()->all())" />
            </fieldset>
        @endcan

        <x-ui.input name="password" :id="$fid('password')" type="password" :required="! $user->exists" placeholder="Tối thiểu 8 ký tự"
                    :label="$user->exists ? 'Mật khẩu mới (để trống nếu không đổi)' : 'Mật khẩu khởi tạo'" autocomplete="new-password" />
    </div>

    {{-- Tab 2: Hồ sơ nhân sự & chuyên môn (có thể bổ sung sau khi tạo) --}}
    <div role="tabpanel" id="{{ $fid('panel-profile') }}" aria-labelledby="{{ $fid('tab-profile') }}" data-tab-panel="profile"
         x-show="tab === 'profile'" @if ($initialTab !== 'profile') x-cloak @endif class="space-y-md">
        <p class="font-caption text-caption text-on-surface-variant">Có thể để trống khi tạo mới và bổ sung khi chỉnh sửa.</p>
        <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
            <x-ui.input name="id_card_number" :id="$fid('id_card_number')" label="Số CCCD (12 số)" :value="$user->id_card_number" placeholder="VD: 001201004567" class="font-code" />
            <x-ui.input name="emergency_contact" :id="$fid('emergency_contact')" label="Liên lạc khẩn cấp (Tên & SĐT)" :value="$user->emergency_contact" placeholder="Tên người thân - SĐT" />
            <x-ui.input name="hometown" :id="$fid('hometown')" label="Quê quán" :value="$user->hometown" placeholder="VD: Hà Nội" />
            <x-ui.input name="current_address" :id="$fid('current_address')" label="Nơi ở hiện tại" :value="$user->current_address" placeholder="Quận/huyện, tỉnh/thành" />
        </div>
        <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
            <x-ui.input name="graduation_school" :id="$fid('graduation_school')" label="Tốt nghiệp" :value="$user->graduation_school" placeholder="VD: ĐH Sư Phạm Hà Nội" />
            <x-ui.input name="certificates" :id="$fid('certificates')" label="Chứng chỉ" :value="$user->certificates" placeholder="VD: IELTS 8.0, TESOL" />
            <x-ui.input name="teaching_level" :id="$fid('teaching_level')" label="Level giảng dạy" :value="$user->teaching_level" placeholder="VD: IELTS Intensive, Pre-G1" />
        </div>
    </div>

    {{-- Tab 3: Hợp đồng lao động & Lương --}}
    <div role="tabpanel" id="{{ $fid('panel-salary') }}" aria-labelledby="{{ $fid('tab-salary') }}" data-tab-panel="salary"
         x-show="tab === 'salary'" @if ($initialTab !== 'salary') x-cloak @endif class="space-y-md">
        <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
            <x-ui.select name="contract_type" :id="$fid('contract_type')" label="Loại hợp đồng" placeholder="-- Chọn loại HĐ --" :value="$user->contract_type" :options="$contractTypes" />
            <x-ui.input name="base_salary" :id="$fid('base_salary')" type="number" min="0" step="1000" label="Lương cơ bản (VNĐ)" :value="$user->base_salary" placeholder="VD: 15000000" class="font-code" />
            <x-ui.input name="hourly_rate" :id="$fid('hourly_rate')" type="number" min="0" step="1000" label="Thù lao giờ dạy (VNĐ)" :value="$user->hourly_rate" placeholder="VD: 250000" class="font-code" />
        </div>
        <div class="grid grid-cols-1 gap-md sm:grid-cols-2">
            <x-ui.date name="contract_start_date" :id="$fid('contract_start_date')" label="Ngày bắt đầu hợp đồng" :value="old('contract_start_date', $user->contract_start_date?->format('Y-m-d'))" />
            <x-ui.date name="contract_end_date" :id="$fid('contract_end_date')" label="Ngày kết thúc hợp đồng" :value="old('contract_end_date', $user->contract_end_date?->format('Y-m-d'))" />
        </div>
        <x-ui.field label="File hợp đồng lao động (PDF, Word, ảnh — tối đa 10MB)" name="contract_file" :for="$fid('contract_file')">
            <input type="file" id="{{ $fid('contract_file') }}" name="contract_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"
                   class="w-full font-body-small text-body-small text-on-surface file:mr-sm file:rounded-lg file:border-0 file:bg-primary-container/10 file:px-md file:py-sm file:font-semibold file:text-primary">
            @if ($user->exists && $user->contract_file_path)
                <p class="flex items-center gap-xs font-caption text-caption text-on-surface-variant">
                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">description</span>
                    Đã có file hợp đồng —
                    <a href="{{ route('users.contract.download', $user) }}" hx-boost="false" class="font-semibold text-primary hover:underline">Tải xuống</a>
                    (tải file mới sẽ thay thế file cũ)
                </p>
            @endif
        </x-ui.field>
    </div>

    @unless ($asModal)
        <div class="flex items-center justify-end gap-sm border-t border-surface-container pt-md">
            <x-ui.button variant="secondary" :href="route('users.index')">Hủy</x-ui.button>
            <x-ui.button type="submit" icon="save">{{ $user->exists ? 'Cập nhật tài khoản' : 'Lưu tài khoản' }}</x-ui.button>
        </div>
    @endunless
</form>
