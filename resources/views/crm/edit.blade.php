<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('crm.customers.show', $customer->id) }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight">Sửa thông tin Khách hàng ({{ $customer->name }})</h1>
                <p class="text-xs text-gray-500 font-mono">Mã hồ sơ: {{ $customer->code }}</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-4xl mx-auto">
        <form action="{{ route('crm.customers.update', $customer->id) }}" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-6">
            @csrf
            @method('PUT')

            @if ($errors->any())
                <div class="p-4 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-700 space-y-1">
                    <div class="font-bold flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-base">error</span>
                        Vui lòng kiểm tra lại các lỗi sau:
                    </div>
                    <ul class="list-disc list-inside pl-2 space-y-0.5">
                        @foreach ($errors->all() as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider mb-4 pb-2 border-b border-gray-100 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[18px]">person</span>
                    Thông tin cơ bản
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Họ và tên <span class="text-rose-500">*</span></label>
                        <input type="text" name="name" value="{{ old('name', $customer->name) }}" required class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3 py-2 font-bold" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Số điện thoại <span class="text-rose-500">*</span></label>
                        <input type="tel" name="phone" value="{{ old('phone', $customer->phone) }}" required class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3 py-2 font-mono" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Tên phụ huynh (nếu có)</label>
                        <input type="text" name="parent_name" value="{{ old('parent_name', $customer->parent_name) }}" placeholder="Nhập tên phụ huynh" class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3 py-2" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Email</label>
                        <input type="email" name="email" value="{{ old('email', $customer->email) }}" class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3 py-2" />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Ngày sinh</label>
                            <input type="date" name="dob" value="{{ old('dob', $customer->dob ? $customer->dob->format('Y-m-d') : '') }}" class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3 py-2" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Giới tính</label>
                            <select name="gender" class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3 py-2">
                                <option value="Nam" {{ old('gender', $customer->gender) === 'Nam' ? 'selected' : '' }}>Nam</option>
                                <option value="Nữ" {{ old('gender', $customer->gender) === 'Nữ' ? 'selected' : '' }}>Nữ</option>
                                <option value="Khác" {{ old('gender', $customer->gender) === 'Khác' ? 'selected' : '' }}>Khác</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Giai đoạn Pipeline</label>
                        <div class="w-full text-xs rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 font-bold text-primary">{{ $customer->stage_label }}</div>
                        <p class="mt-1 text-[11px] text-gray-500">Đổi giai đoạn tại Pipeline; trạng thái Won chỉ được tạo qua Closing Wizard.</p>
                    </div>
                    @can('lead.assign')
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Sales phụ trách</label>
                        <select name="assigned_user_id" class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3 py-2">
                            @foreach ($salesUsers as $u)
                                <option value="{{ $u->id }}" {{ old('assigned_user_id', $customer->assigned_user_id) == $u->id ? 'selected' : '' }}>{{ $u->name }} ({{ $u->email }})</option>
                            @endforeach
                        </select>
                    </div>
                    @endcan
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Cơ sở đăng ký</label>
                        <select name="branch_id" class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3 py-2">
                            <option value="">-- Chọn cơ sở --</option>
                            @foreach ($branches as $br)
                                <option value="{{ $br->id }}" {{ old('branch_id', $customer->branch_id) == $br->id ? 'selected' : '' }}>{{ $br->name }} ({{ $br->code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Nguồn tiếp cận <span class="text-rose-500">*</span></label>
                        <select name="source" required class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3 py-2">
                            <option value="" {{ old('source', $customer->source) ? 'hidden' : 'selected' }} disabled>-- Chọn nguồn --</option>
                            @foreach ($leadSources as $src)
                                <option value="{{ $src }}" {{ old('source', $customer->source) === $src ? 'selected' : '' }}>{{ $src }}</option>
                            @endforeach
                            @if ($customer->source && ! $leadSources->contains($customer->source))
                                <option value="{{ $customer->source }}" selected>{{ $customer->source }} (hiện tại)</option>
                            @endif
                        </select>
                        @error('source') <p class="mt-1 text-[11px] text-rose-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Khóa học quan tâm</label>
                        <input type="text" name="course_interest" value="{{ old('course_interest', $customer->course_interest) }}" placeholder="VD: IELTS 6.5 Intensive" list="course-interest-options" class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3 py-2 font-semibold" />
                        <datalist id="course-interest-options">
                            <option value="IELTS 6.5 Intensive">IELTS 6.5 Intensive</option>
                            <option value="IELTS 7.0 Master">IELTS 7.0 Master</option>
                            <option value="Giao tiếp Pro B1">Giao tiếp Pro B1</option>
                            <option value="Giao tiếp Pro B2">Giao tiếp Pro B2</option>
                            <option value="Tiếng Anh Mất Gốc">Tiếng Anh Mất Gốc</option>
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Giá trị deal (VNĐ)</label>
                        <input type="number" name="deal_value" value="{{ old('deal_value', $customer->deal_value) }}" class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3 py-2" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Địa chỉ</label>
                        <input type="text" name="address" value="{{ old('address', $customer->address) }}" class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3 py-2" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Ghi chú nhu cầu</label>
                        <textarea name="notes" rows="3" class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3 py-2">{{ old('notes', $customer->notes) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                <a href="{{ route('crm.customers.show', $customer->id) }}" class="px-4 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50 transition">
                    Hủy bỏ
                </a>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-primary hover:bg-primary-hover text-white text-xs font-bold shadow-md transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">check</span>
                    <span>Cập nhật vào Database</span>
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
