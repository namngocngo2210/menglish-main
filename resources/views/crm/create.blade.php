<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('crm.customers.index') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight">Thêm khách mới</h1>
                    <p class="text-xs text-gray-500">Nhập nhanh thông tin cơ bản của khách hàng tiềm năng</p>
                </div>
            </div>
            <a href="{{ route('crm.customers.index') }}" class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-xl transition" title="Đóng">
                <span class="material-symbols-outlined text-2xl">close</span>
            </a>
        </div>
    </x-slot>

    <div class="max-w-xl mx-auto py-4">
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <!-- Header bar of the card -->
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-xl">person_add</span>
                    <h2 class="font-bold text-sm text-gray-900">Thêm khách mới</h2>
                </div>
                <a href="{{ route('crm.customers.index') }}" class="text-gray-400 hover:text-gray-600 transition">
                    <span class="material-symbols-outlined text-xl">close</span>
                </a>
            </div>

            <form action="{{ route('crm.customers.store') }}" method="POST" class="p-6 space-y-4">
                @csrf

                <!-- Validation Errors banner -->
                @if ($errors->any())
                    <div class="p-3.5 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-700 space-y-1">
                        <div class="font-bold flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-base">error</span>
                            <span>Vui lòng kiểm tra lại thông tin:</span>
                        </div>
                        <ul class="list-disc list-inside pl-1 space-y-0.5">
                            @foreach ($errors->all() as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <!-- 1. Họ và tên * -->
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">
                        Họ và tên <span class="text-rose-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="name" 
                        value="{{ old('name') }}" 
                        required 
                        placeholder="Nhập họ và tên khách" 
                        class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3.5 py-2.5 bg-white transition placeholder:text-gray-400"
                    />
                    @error('name') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1.5">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}" placeholder="hocvien@example.com" class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3.5 py-2.5" />
                        @error('email') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1.5">Ngày sinh</label>
                        <input type="date" name="dob" value="{{ old('dob') }}" class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3.5 py-2.5" />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1.5">Giới tính</label>
                        <select name="gender" class="w-full text-xs rounded-xl border border-gray-200 px-3.5 py-2.5">
                            <option value="">-- Chọn --</option>
                            @foreach (['Nam', 'Nữ', 'Khác'] as $gender)<option value="{{ $gender }}" @selected(old('gender') === $gender)>{{ $gender }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1.5">Khóa học quan tâm</label>
                        <input type="text" name="course_interest" value="{{ old('course_interest') }}" placeholder="Ví dụ: IELTS 6.5" class="w-full text-xs rounded-xl border border-gray-200 px-3.5 py-2.5" />
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Địa chỉ</label>
                    <input type="text" name="address" value="{{ old('address') }}" class="w-full text-xs rounded-xl border border-gray-200 px-3.5 py-2.5" />
                </div>

                <!-- 2. Số điện thoại * -->
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">
                        Số điện thoại <span class="text-rose-500">*</span>
                    </label>
                    <input 
                        type="tel" 
                        name="phone" 
                        value="{{ old('phone') }}" 
                        required 
                        placeholder="Nhập số điện thoại" 
                        class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3.5 py-2.5 bg-white transition placeholder:text-gray-400 font-mono"
                    />
                    @error('phone') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <!-- 3. Tên phụ huynh (tùy chọn) -->
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">
                        Tên phụ huynh <span class="text-gray-400 font-normal">(tùy chọn)</span>
                    </label>
                    <input 
                        type="text" 
                        name="parent_name" 
                        value="{{ old('parent_name') }}" 
                        placeholder="Nhập tên phụ huynh nếu có" 
                        class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3.5 py-2.5 bg-white transition placeholder:text-gray-400"
                    />
                    @error('parent_name') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <!-- 4. Nguồn khách * -->
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">
                        Nguồn khách <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative">
                        <select 
                            name="source" 
                            required 
                            class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3.5 py-2.5 bg-white transition appearance-none pr-10 cursor-pointer text-gray-700"
                        >
                            <option value="">Chọn nguồn khách</option>
                            @foreach ($leadSources as $src)
                                <option value="{{ $src }}" {{ old('source') === $src ? 'selected' : '' }}>{{ $src }}</option>
                            @endforeach
                        </select>
                        <span class="material-symbols-outlined absolute right-3 top-2.5 text-gray-400 pointer-events-none text-xl">keyboard_arrow_down</span>
                    </div>
                    @error('source') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <!-- 5. Chi nhánh * -->
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">
                        Chi nhánh <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative">
                        <select 
                            name="branch_id" 
                            required 
                            class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3.5 py-2.5 bg-white transition appearance-none pr-10 cursor-pointer text-gray-700 font-semibold"
                        >
                            <option value="">-- Chọn cơ sở chi nhánh --</option>
                            @foreach ($branches as $br)
                                <option value="{{ $br->id }}" {{ old('branch_id') == $br->id ? 'selected' : '' }}>
                                    {{ $br->name }} ({{ $br->code }})
                                </option>
                            @endforeach
                        </select>
                        <span class="material-symbols-outlined absolute right-3 top-2.5 text-gray-400 pointer-events-none text-xl">keyboard_arrow_down</span>
                    </div>
                    @error('branch_id') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                @can('lead.assign')
                <!-- 6. Gán người phụ trách -->
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">
                        Gán người phụ trách
                    </label>
                    <div class="relative">
                        <select 
                            name="assigned_user_id" 
                            class="w-full text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary focus:border-primary px-3.5 py-2.5 bg-white transition appearance-none pr-10 cursor-pointer text-gray-700"
                        >
                            <option value="">-- Chọn tư vấn viên / Sales phụ trách --</option>
                            @foreach ($salesUsers as $u)
                                <option value="{{ $u->id }}" {{ old('assigned_user_id', Auth::id()) == $u->id ? 'selected' : '' }}>
                                    {{ $u->name }} ({{ $u->email }})
                                </option>
                            @endforeach
                        </select>
                        <span class="material-symbols-outlined absolute right-3 top-2.5 text-gray-400 pointer-events-none text-xl">keyboard_arrow_down</span>
                    </div>
                    @error('assigned_user_id') <span class="text-rose-500 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>
                @endcan

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Giá trị dự kiến (VNĐ)</label>
                    <input type="number" min="0" name="deal_value" value="{{ old('deal_value', 0) }}" class="w-full text-xs rounded-xl border border-gray-200 px-3.5 py-2.5 font-mono" />
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1.5">Ghi chú ban đầu</label>
                    <textarea name="notes" rows="3" class="w-full text-xs rounded-xl border border-gray-200 px-3.5 py-2.5">{{ old('notes') }}</textarea>
                </div>

                <div class="pt-4 border-t border-gray-100 flex items-center justify-end gap-3">
                    <a href="{{ route('crm.customers.index') }}" class="px-4 py-2.5 rounded-xl border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50 transition">
                        Hủy
                    </a>
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-primary hover:bg-primary-hover text-white text-xs font-bold shadow-md transition flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[18px]">person_add</span>
                        <span>Thêm khách mới</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
