<x-app-layout>
    <x-ui.page-header title="Quản Lý Cơ Sở & Chi Nhánh Trung Tâm" icon="apartment">
        <x-slot:breadcrumbs>
            <a href="{{ route('dashboard') }}" class="hover:text-on-surface transition flex items-center gap-1">
                <span class="material-symbols-outlined text-[16px]">home</span>
                <span>Trang chủ</span>
            </a>
            <span class="material-symbols-outlined text-[14px]">chevron_right</span>
            <span class="text-primary-container font-semibold">Cơ sở &amp; Chi nhánh</span>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            <x-ui.button icon="add_business" onclick="document.getElementById('createBranchModal').classList.remove('hidden')">Thêm Chi Nhánh Mới</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6" x-data="{
        editModal: false,
        editData: { id: null, code: '', name: '', address: '', phone: '', is_active: true },
        openEdit(b) {
            this.editData = { ...b };
            this.editModal = true;
        }
    }">

        {{-- Flash messages --}}

        @if (session('error') || $errors->any())
            <div class="p-4 bg-rose-50 border border-rose-200 rounded-2xl text-xs font-semibold text-rose-800 flex items-center gap-2 shadow-2xs">
                <span class="material-symbols-outlined text-rose-600 text-base">error</span>
                <span>{{ session('error') ?? $errors->first() }}</span>
            </div>
        @endif

        {{-- Quick Stats Overview --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-xl">apartment</span>
                </div>
                <div>
                    <span class="text-[11px] text-gray-500 font-bold uppercase tracking-wider block">Tổng chi nhánh</span>
                    <span class="text-xl font-black text-gray-900 font-mono">{{ $totalBranches }}</span>
                </div>
            </div>

            <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-xl">check_circle</span>
                </div>
                <div>
                    <span class="text-[11px] text-emerald-700 font-bold uppercase tracking-wider block">Đang hoạt động</span>
                    <span class="text-xl font-black text-emerald-700 font-mono">{{ $activeBranches }}</span>
                </div>
            </div>

            <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-orange-50 text-primary-container flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-xl">school</span>
                </div>
                <div>
                    <span class="text-[11px] text-gray-500 font-bold uppercase tracking-wider block">Tổng học viên</span>
                    <span class="text-xl font-black text-gray-900 font-mono">{{ $totalStudents }}</span>
                </div>
            </div>

            <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-xl">meeting_room</span>
                </div>
                <div>
                    <span class="text-[11px] text-gray-500 font-bold uppercase tracking-wider block">Lớp đang mở</span>
                    <span class="text-xl font-black text-gray-900 font-mono">{{ $totalClasses }}</span>
                </div>
            </div>
        </div>

        {{-- Filter & Search Bar --}}
        <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-3">
            <form action="{{ route('branches.index') }}" method="GET" class="flex items-center gap-2 w-full sm:w-auto flex-1">
                <div class="relative flex-1 sm:max-w-xs">
                    <span class="material-symbols-outlined absolute left-3 top-2.5 text-gray-400 text-lg">search</span>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Tìm tên, mã, địa chỉ, số hotline..." class="w-full text-xs pl-9 pr-3 py-2 rounded-xl border border-gray-200 focus:border-primary-container focus:ring-primary-container shadow-2xs" />
                </div>
                <select name="status" class="text-xs rounded-xl border border-gray-200 py-2 px-3 bg-white focus:border-primary-container focus:ring-primary-container shadow-2xs" onchange="this.form.submit()">
                    <option value="">Tất cả trạng thái</option>
                    <option value="1" @selected(request('status') === '1')>Đang hoạt động</option>
                    <option value="0" @selected(request('status') === '0')>Tạm dừng</option>
                </select>
                @if (request('search') || request('status') !== null)
                    <a href="{{ route('branches.index') }}" class="p-2 text-gray-400 hover:text-gray-600 rounded-xl hover:bg-gray-100" title="Xóa bộ lọc">
                        <span class="material-symbols-outlined text-lg">refresh</span>
                    </a>
                @endif
            </form>
            <div class="text-xs text-gray-500 font-mono shrink-0">
                Hiển thị: <strong>{{ $branches->count() }}</strong> cơ sở chi nhánh
            </div>
        </div>

        {{-- Branches Table List --}}
        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3.5 px-4 w-16 text-center">STT</th>
                            <th class="py-3.5 px-4 w-28">Mã cơ sở</th>
                            <th class="py-3.5 px-4">Tên Chi Nhánh &amp; Địa Chỉ</th>
                            <th class="py-3.5 px-4 w-36">Hotline liên hệ</th>
                            <th class="py-3.5 px-4 text-center w-36">Quy mô hoạt động</th>
                            <th class="py-3.5 px-4 text-center w-32">Trạng thái</th>
                            <th class="py-3.5 px-4 text-right w-24">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($branches as $idx => $branch)
                            <tr class="hover:bg-slate-50/60 transition">
                                <td class="py-4 px-4 text-center font-mono font-bold text-gray-400">
                                    {{ $idx + 1 }}
                                </td>
                                <td class="py-4 px-4 font-mono font-bold text-indigo-700">
                                    <span class="px-2.5 py-1 rounded-lg bg-indigo-50 border border-indigo-100">
                                        {{ $branch->code }}
                                    </span>
                                </td>
                                <td class="py-4 px-4">
                                    <div class="font-bold text-gray-900 text-sm mb-0.5">{{ $branch->name }}</div>
                                    <div class="text-[11px] text-gray-500 flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[13px] text-gray-400">location_on</span>
                                        <span>{{ $branch->address }}</span>
                                    </div>
                                </td>
                                <td class="py-4 px-4 font-mono font-medium text-gray-700">
                                    {{ $branch->phone ?: 'Chưa cập nhật' }}
                                </td>
                                <td class="py-4 px-4 text-center">
                                    <div class="flex items-center justify-center gap-2 text-[11px] font-mono">
                                        <span class="px-2 py-0.5 rounded bg-blue-50 text-blue-700 font-bold" title="Số lớp học">
                                            {{ $branch->classes_count }} Lớp
                                        </span>
                                        <span class="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 font-bold" title="Số học viên">
                                            {{ $branch->students_count }} HV
                                        </span>
                                        <span class="px-2 py-0.5 rounded bg-purple-50 text-purple-700 font-bold" title="Nhân sự phụ trách">
                                            {{ $branch->users_count }} NS
                                        </span>
                                    </div>
                                </td>
                                <td class="py-4 px-4 text-center">
                                    <form action="{{ route('branches.toggle', $branch->id) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="cursor-pointer" title="Click để Bật / Tắt hoạt động">
                                            @if ($branch->is_active)
                                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 uppercase flex items-center gap-1">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                    <span>Hoạt động</span>
                                                </span>
                                            @else
                                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-gray-100 text-gray-500 border border-gray-200 uppercase flex items-center gap-1">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                                    <span>Tạm dừng</span>
                                                </span>
                                            @endif
                                        </button>
                                    </form>
                                </td>
                                <td class="py-4 px-4 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1">
                                        <button type="button" @click="openEdit({{ Js::from($branch) }})" class="p-1.5 rounded-lg text-gray-500 hover:text-primary-container hover:bg-orange-50 transition cursor-pointer" title="Chỉnh sửa">
                                            <span class="material-symbols-outlined text-[18px]">edit</span>
                                        </button>
                                        <form action="{{ route('branches.destroy', $branch->id) }}" method="POST" class="inline" data-confirm="Bạn có chắc chắn muốn xóa chi nhánh {{ $branch->name }}?">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="p-1.5 rounded-lg text-gray-400 hover:text-rose-600 hover:bg-rose-50 transition cursor-pointer" title="Xóa">
                                                <span class="material-symbols-outlined text-[18px]">delete</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center text-gray-400">
                                    <span class="material-symbols-outlined text-4xl mb-1 text-gray-300">apartment</span>
                                    <p>Không tìm thấy cơ sở chi nhánh nào phù hợp.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ────────────────────────────────────────────── --}}
        {{-- MODAL: THÊM CHI NHÁNH MỚI --}}
        {{-- ────────────────────────────────────────────── --}}
        <div id="createBranchModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
                <div class="flex justify-between items-center pb-2 border-b border-gray-100">
                    <h3 class="font-bold text-sm text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary-container">add_business</span>
                        <span>Thêm Cơ Sở Chi Nhánh Mới</span>
                    </h3>
                    <button type="button" onclick="document.getElementById('createBranchModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 cursor-pointer">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>

                <form action="{{ route('branches.store') }}" method="POST" class="space-y-3 text-xs">
                    @csrf
                    <div class="grid grid-cols-3 gap-3">
                        <div class="col-span-1">
                            <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Mã chi nhánh <span class="text-rose-500">*</span></label>
                            <input type="text" name="code" required placeholder="VD: CG" class="w-full text-xs font-mono font-bold uppercase rounded-xl border border-gray-300 p-2.5 focus:border-primary-container focus:ring-primary-container" />
                        </div>
                        <div class="col-span-2">
                            <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Tên chi nhánh <span class="text-rose-500">*</span></label>
                            <input type="text" name="name" required placeholder="VD: Chi nhánh Cầu Giấy" class="w-full text-xs font-bold rounded-xl border border-gray-300 p-2.5 focus:border-primary-container focus:ring-primary-container" />
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Địa chỉ chi nhánh <span class="text-rose-500">*</span></label>
                        <input type="text" name="address" required placeholder="Số nhà, Đường, Quận, Thành phố..." class="w-full text-xs rounded-xl border border-gray-300 p-2.5 focus:border-primary-container focus:ring-primary-container" />
                    </div>

                    <div>
                        <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Số điện thoại Hotline</label>
                        <input type="text" name="phone" placeholder="0243 555 0101" class="w-full text-xs font-mono rounded-xl border border-gray-300 p-2.5 focus:border-primary-container focus:ring-primary-container" />
                    </div>

                    <div class="pt-1 flex items-center gap-2">
                        <input type="checkbox" name="is_active" value="1" id="is_active_create" checked class="w-4 h-4 text-orange-600 focus:ring-orange-500 rounded border-gray-300 cursor-pointer" />
                        <label for="is_active_create" class="text-xs font-semibold text-gray-700 cursor-pointer">Kích hoạt chi nhánh ngay sau khi tạo</label>
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                        <button type="button" onclick="document.getElementById('createBranchModal').classList.add('hidden')" class="px-3 py-2 rounded-xl border text-xs text-gray-600 hover:bg-gray-50 cursor-pointer">Hủy</button>
                        <button type="submit" class="px-4 py-2 bg-primary-container hover:bg-primary text-white text-xs font-bold rounded-xl shadow-xs transition cursor-pointer">Tạo Chi Nhánh</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- ────────────────────────────────────────────── --}}
        {{-- MODAL: SỬA CHI NHÁNH --}}
        {{-- ────────────────────────────────────────────── --}}
        <div x-show="editModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" style="display: none;">
            <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl" @click.outside="editModal = false">
                <div class="flex justify-between items-center pb-2 border-b border-gray-100">
                    <h3 class="font-bold text-sm text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-outlined text-indigo-600">edit_business</span>
                        <span>Chỉnh Sửa Cơ Sở Chi Nhánh</span>
                    </h3>
                    <button type="button" @click="editModal = false" class="text-gray-400 hover:text-gray-600 cursor-pointer">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>

                <form :action="'/branches/' + editData.id" method="POST" class="space-y-3 text-xs">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-3 gap-3">
                        <div class="col-span-1">
                            <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Mã chi nhánh <span class="text-rose-500">*</span></label>
                            <input type="text" name="code" x-model="editData.code" required class="w-full text-xs font-mono font-bold uppercase rounded-xl border border-gray-300 p-2.5 focus:border-primary-container focus:ring-primary-container" />
                        </div>
                        <div class="col-span-2">
                            <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Tên chi nhánh <span class="text-rose-500">*</span></label>
                            <input type="text" name="name" x-model="editData.name" required class="w-full text-xs font-bold rounded-xl border border-gray-300 p-2.5 focus:border-primary-container focus:ring-primary-container" />
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Địa chỉ chi nhánh <span class="text-rose-500">*</span></label>
                        <input type="text" name="address" x-model="editData.address" required class="w-full text-xs rounded-xl border border-gray-300 p-2.5 focus:border-primary-container focus:ring-primary-container" />
                    </div>

                    <div>
                        <label class="block font-bold text-gray-700 mb-1 uppercase text-[10px]">Số điện thoại Hotline</label>
                        <input type="text" name="phone" x-model="editData.phone" class="w-full text-xs font-mono rounded-xl border border-gray-300 p-2.5 focus:border-primary-container focus:ring-primary-container" />
                    </div>

                    <div class="pt-1 flex items-center gap-2">
                        <input type="checkbox" name="is_active" value="1" id="is_active_edit" :checked="editData.is_active" class="w-4 h-4 text-orange-600 focus:ring-orange-500 rounded border-gray-300 cursor-pointer" />
                        <label for="is_active_edit" class="text-xs font-semibold text-gray-700 cursor-pointer">Trạng thái: Đang hoạt động</label>
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                        <button type="button" @click="editModal = false" class="px-3 py-2 rounded-xl border text-xs text-gray-600 hover:bg-gray-50 cursor-pointer">Hủy</button>
                        <button type="submit" class="px-4 py-2 bg-primary-container hover:bg-primary text-white text-xs font-bold rounded-xl shadow-xs transition cursor-pointer">Lưu Thay Đổi</button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</x-app-layout>