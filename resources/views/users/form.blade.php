<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('users.index') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight">{{ $user->exists ? 'Sửa thông tin nhân sự' : 'Thêm nhân viên mới' }}</h1>
                    <p class="text-xs text-gray-500">Nhập đầy đủ thông tin tài khoản, vai trò và cơ sở công tác</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="max-w-2xl mx-auto bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
        <form method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="space-y-5">
            @csrf
            @if ($user->exists) @method('PUT') @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="name" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Họ và tên <span class="text-rose-600">*</span></label>
                    <input type="text" id="name" name="name" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 shadow-2xs" value="{{ old('name', $user->name) }}" placeholder="VD: Nguyễn Văn A" required>
                    <x-input-error :messages="$errors->get('name')" class="mt-1 text-xs" />
                </div>
                <div>
                    <label for="employee_code" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Mã nhân viên</label>
                    <input type="text" id="employee_code" name="employee_code" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 font-mono shadow-2xs" value="{{ old('employee_code', $user->employee_code) }}" placeholder="VD: NV-0012">
                    <x-input-error :messages="$errors->get('employee_code')" class="mt-1 text-xs" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="email" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Email công việc <span class="text-rose-600">*</span></label>
                    <input type="email" id="email" name="email" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 shadow-2xs" value="{{ old('email', $user->email) }}" placeholder="VD: nva@menglish.edu.vn" required>
                    <x-input-error :messages="$errors->get('email')" class="mt-1 text-xs" />
                </div>
                <div>
                    <label for="phone" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Số điện thoại</label>
                    <input type="text" id="phone" name="phone" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 font-mono shadow-2xs" value="{{ old('phone', $user->phone) }}" placeholder="VD: 0912 345 678">
                    <x-input-error :messages="$errors->get('phone')" class="mt-1 text-xs" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="branch_id" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Cơ sở / Chi nhánh <span class="text-rose-600">*</span></label>
                    <select id="branch_id" name="branch_id" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 shadow-2xs" required>
                        <option value="">-- Chọn cơ sở --</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected(old('branch_id', $user->branch_id) == $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('branch_id')" class="mt-1 text-xs" />
                </div>
                <div>
                    <label for="role" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Vai trò &amp; Chức vụ <span class="text-rose-600">*</span></label>
                    <select id="role" name="role" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 shadow-2xs" required>
                        <option value="">-- Chọn vai trò --</option>
                        @foreach ($roles as $roleName)
                            <option value="{{ $roleName }}" @selected(old('role', $user->getRoleNames()->first()) == $roleName)>{{ \App\Helpers\AclHelper::roleLabel($roleName) }} ({{ $roleName }})</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('role')" class="mt-1 text-xs" />
                </div>
            </div>

            <div>
                <label for="password" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">
                    {{ $user->exists ? 'Mật khẩu mới (để trống nếu không đổi)' : 'Mật khẩu khởi tạo *' }}
                </label>
                <input type="password" id="password" name="password" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 shadow-2xs" {{ $user->exists ? '' : 'required' }} placeholder="Tối thiểu 8 ký tự">
                <x-input-error :messages="$errors->get('password')" class="mt-1 text-xs" />
            </div>

            <!-- Hồ sơ nhân sự & Thông tin chuyên môn (Có thể cập nhật sau khi tạo) -->
            <div class="pt-4 border-t border-gray-200 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wider flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[#F5691A] text-[18px]">badge</span>
                        Hồ sơ nhân sự chi tiết
                    </h3>
                    <span class="text-[11px] text-gray-400">Có thể để trống khi tạo mới và bổ sung khi chỉnh sửa</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="id_card_number" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Số CCCD (12 số)</label>
                        <input type="text" id="id_card_number" name="id_card_number" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 font-mono shadow-2xs" value="{{ old('id_card_number', $user->id_card_number) }}" placeholder="VD: 001201004567">
                        <x-input-error :messages="$errors->get('id_card_number')" class="mt-1 text-xs" />
                    </div>
                    <div>
                        <label for="emergency_contact" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Liên lạc khẩn cấp (Tên & SĐT)</label>
                        <input type="text" id="emergency_contact" name="emergency_contact" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 shadow-2xs" value="{{ old('emergency_contact', $user->emergency_contact) }}" placeholder="VD: Mẹ - 0988 776 655">
                        <x-input-error :messages="$errors->get('emergency_contact')" class="mt-1 text-xs" />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="hometown" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Quê quán</label>
                        <input type="text" id="hometown" name="hometown" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 shadow-2xs" value="{{ old('hometown', $user->hometown) }}" placeholder="VD: Hà Nội">
                        <x-input-error :messages="$errors->get('hometown')" class="mt-1 text-xs" />
                    </div>
                    <div>
                        <label for="current_address" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Nơi ở hiện tại</label>
                        <input type="text" id="current_address" name="current_address" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 shadow-2xs" value="{{ old('current_address', $user->current_address) }}" placeholder="VD: Cầu Giấy, Hà Nội">
                        <x-input-error :messages="$errors->get('current_address')" class="mt-1 text-xs" />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label for="graduation_school" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Tốt nghiệp</label>
                        <input type="text" id="graduation_school" name="graduation_school" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 shadow-2xs" value="{{ old('graduation_school', $user->graduation_school) }}" placeholder="VD: ĐH Sư Phạm Hà Nội">
                    </div>
                    <div>
                        <label for="certificates" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Chứng chỉ</label>
                        <input type="text" id="certificates" name="certificates" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 shadow-2xs" value="{{ old('certificates', $user->certificates) }}" placeholder="VD: IELTS 8.0, TESOL">
                    </div>
                    <div>
                        <label for="teaching_level" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Level giảng dạy</label>
                        <input type="text" id="teaching_level" name="teaching_level" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 shadow-2xs" value="{{ old('teaching_level', $user->teaching_level) }}" placeholder="VD: IELTS Intensive, Pre-G1">
                    </div>
                </div>

                <!-- Thông tin hợp đồng & chế độ lương -->
                <div class="pt-3 border-t border-dashed border-gray-200 space-y-3">
                    <h4 class="text-xs font-bold text-gray-800 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-blue-600 text-[16px]">description</span>
                        Hợp đồng lao động & Lương
                    </h4>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label for="contract_type" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Loại hợp đồng</label>
                            <select id="contract_type" name="contract_type" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 shadow-2xs">
                                <option value="">-- Chọn loại HĐ --</option>
                                <option value="Toàn thời gian" @selected(old('contract_type', $user->contract_type) == 'Toàn thời gian')>Toàn thời gian (Fulltime)</option>
                                <option value="Bán thời gian" @selected(old('contract_type', $user->contract_type) == 'Bán thời gian')>Bán thời gian (Parttime)</option>
                                <option value="Thử việc" @selected(old('contract_type', $user->contract_type) == 'Thử việc')>Thử việc</option>
                                <option value="Cộng tác viên" @selected(old('contract_type', $user->contract_type) == 'Cộng tác viên')>Cộng tác viên / Trợ giảng</option>
                            </select>
                        </div>
                        <div>
                            <label for="base_salary" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Lương cơ bản (VNĐ)</label>
                            <input type="number" id="base_salary" name="base_salary" min="0" step="1000" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 font-mono shadow-2xs" value="{{ old('base_salary', $user->base_salary) }}" placeholder="VD: 15000000">
                        </div>
                        <div>
                            <label for="hourly_rate" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Thù lao giờ dạy (VNĐ)</label>
                            <input type="number" id="hourly_rate" name="hourly_rate" min="0" step="1000" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 font-mono shadow-2xs" value="{{ old('hourly_rate', $user->hourly_rate) }}" placeholder="VD: 250000">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="contract_start_date" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Ngày bắt đầu hợp đồng</label>
                            <input type="date" id="contract_start_date" name="contract_start_date" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 shadow-2xs" value="{{ old('contract_start_date', $user->contract_start_date?->format('Y-m-d')) }}">
                        </div>
                        <div>
                            <label for="contract_end_date" class="block text-[11px] font-bold uppercase text-gray-600 mb-1">Ngày kết thúc hợp đồng</label>
                            <input type="date" id="contract_end_date" name="contract_end_date" class="w-full bg-white border border-gray-200 text-gray-900 rounded-xl text-sm focus:ring-[#F5691A] focus:border-[#F5691A] p-2.5 shadow-2xs" value="{{ old('contract_end_date', $user->contract_end_date?->format('Y-m-d')) }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-gray-100 flex items-center justify-end gap-3">
                <a href="{{ route('users.index') }}" class="px-4 py-2.5 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-700 text-xs font-semibold transition">
                    Hủy
                </a>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-[#F5691A] hover:bg-[#d85a15] text-white text-xs font-semibold shadow-sm transition">
                    {{ $user->exists ? 'Cập nhật tài khoản' : 'Lưu tài khoản' }}
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
