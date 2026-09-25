<x-app-layout>
    @include('crm.partials.header-tabs')

    <!-- Success flash banner -->

    <div class="space-y-4">
        <!-- Search & Filters -->
        <form method="GET" action="{{ route('crm.customers.index') }}" class="bg-white rounded-2xl p-4 shadow-sm border border-gray-200 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2 flex-1">
                <div class="relative min-w-[240px]">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">search</span>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Tìm theo tên, SĐT, mã KH..." class="w-full pl-9 pr-3 py-1.5 text-xs rounded-xl border border-gray-200 focus:ring-1 focus:ring-primary-container focus:border-primary-container" />
                </div>
                <select name="branch_id" class="text-xs rounded-xl border border-gray-200 py-1.5 px-3" onchange="this.form.submit()">
                    <option value="">Tất cả cơ sở</option>
                    @foreach ($branches as $br)
                        <option value="{{ $br->id }}" {{ request('branch_id') == $br->id ? 'selected' : '' }}>{{ $br->name }}</option>
                    @endforeach
                </select>
                <select name="stage" class="text-xs rounded-xl border border-gray-200 py-1.5 px-3" onchange="this.form.submit()">
                    <option value="">Tất cả trạng thái</option>
                    <option value="new" {{ request('stage') === 'new' ? 'selected' : '' }}>Mới tiếp nhận</option>
                    <option value="consulting" {{ request('stage') === 'consulting' ? 'selected' : '' }}>Tư vấn lộ trình</option>
                    <option value="test_scheduled" {{ request('stage') === 'test_scheduled' ? 'selected' : '' }}>Hẹn Test</option>
                    <option value="tested" {{ request('stage') === 'tested' ? 'selected' : '' }}>Đã Test</option>
                    <option value="trial_scheduled" {{ request('stage') === 'trial_scheduled' ? 'selected' : '' }}>Hẹn học thử</option>
                    <option value="trial_completed" {{ request('stage') === 'trial_completed' ? 'selected' : '' }}>Đã học thử</option>
                    <option value="waiting_class" {{ request('stage') === 'waiting_class' ? 'selected' : '' }}>Chờ xếp lớp</option>
                    <option value="closing" {{ request('stage') === 'closing' ? 'selected' : '' }}>Chờ thanh toán</option>
                    <option value="won" {{ request('stage') === 'won' ? 'selected' : '' }}>Đã chốt (Won)</option>
                    <option value="lost" {{ request('stage') === 'lost' ? 'selected' : '' }}>Không chốt (Lost)</option>
                </select>
                <button type="submit" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold rounded-xl transition">
                    Lọc dữ liệu
                </button>
                @if (request()->hasAny(['search', 'branch_id', 'stage']))
                    <a href="{{ route('crm.customers.index') }}" class="text-xs text-rose-500 hover:underline">Xóa bộ lọc</a>
                @endif
            </div>
            <div class="text-xs text-gray-500">
                Hiển thị <strong class="text-gray-900">{{ $customers->total() }}</strong> khách hàng
            </div>
        </form>

        <!-- Customer Table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs min-w-[1020px]">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4 min-w-[180px] whitespace-nowrap">Mã &amp; Họ tên</th>
                        <th class="py-3 px-4 min-w-[120px] whitespace-nowrap">Số điện thoại</th>
                        <th class="py-3 px-4 min-w-[160px] whitespace-nowrap">Cơ sở &amp; Nguồn</th>
                        <th class="py-3 px-4 min-w-[160px] whitespace-nowrap">Khóa học quan tâm</th>
                        <th class="py-3 px-4 min-w-[140px] whitespace-nowrap">Trạng thái Pipeline</th>
                        <th class="py-3 px-4 min-w-[140px] whitespace-nowrap">Sales phụ trách</th>
                        <th class="py-3 px-4 min-w-[120px] whitespace-nowrap">Ngày tạo</th>
                        <th class="py-3 px-4 text-right min-w-[110px] whitespace-nowrap">Thao tác</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                    @forelse ($customers as $c)
                        <tr class="hover:bg-orange-50/20 transition">
                            <td class="py-3.5 px-4 font-medium whitespace-nowrap">
                                <a href="{{ route('crm.customers.show', $c->id) }}" class="font-bold text-gray-900 text-sm hover:text-primary transition flex items-center gap-2 whitespace-nowrap">
                                    <span class="w-7 h-7 rounded-full bg-primary-container/10 text-primary flex items-center justify-center font-bold text-xs shrink-0">
                                        {{ Str::substr($c->name, 0, 1) }}
                                    </span>
                                    <span class="whitespace-nowrap">{{ $c->name }}</span>
                                </a>
                                <div class="text-[11px] text-gray-400 font-mono pl-9 whitespace-nowrap">{{ $c->code }}</div>
                            </td>
                            <td class="py-3.5 px-4 font-mono text-gray-800 whitespace-nowrap">{{ $c->phone }}</td>
                            <td class="py-3.5 px-4 whitespace-nowrap">
                                <div class="font-semibold text-gray-900 whitespace-nowrap">{{ $c->branch?->name ?? 'Chưa chọn cơ sở' }}</div>
                                <div class="text-[11px] text-gray-400 whitespace-nowrap">{{ $c->source }}</div>
                            </td>
                            <td class="py-3.5 px-4 font-semibold text-primary whitespace-nowrap">
                                <div class="whitespace-nowrap">{{ $c->course_interest ?? 'Chưa chọn' }}</div>
                                <div class="text-[10px] text-gray-400 font-mono whitespace-nowrap">{{ number_format($c->deal_value) }}đ</div>
                            </td>
                            <td class="py-3.5 px-4 whitespace-nowrap">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border {{ $c->stage_badge }} whitespace-nowrap inline-block">
                                    {{ $c->stage_label }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 font-medium text-gray-800 whitespace-nowrap">{{ $c->assignedUser?->name ?? 'Chưa phân công' }}</td>
                            <td class="py-3.5 px-4 text-gray-500 font-mono whitespace-nowrap">{{ $c->created_at->format('d/m/Y H:i') }}</td>
                            <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1.5 whitespace-nowrap">
                                    <a href="{{ route('crm.customers.show', $c->id) }}" class="p-1 rounded-lg hover:bg-gray-100 text-gray-600 hover:text-primary transition shrink-0" title="Xem chi tiết">
                                        <span class="material-symbols-outlined text-[18px]">visibility</span>
                                    </a>
                                    @can('lead.update')
                                    <a href="{{ route('crm.customers.edit', $c->id) }}" class="p-1 rounded-lg hover:bg-gray-100 text-gray-600 hover:text-indigo-600 transition" title="Sửa thông tin">
                                        <span class="material-symbols-outlined text-[18px]">edit</span>
                                    </a>
                                    @endcan
                                    @can('lead.delete')
                                    <form action="{{ route('crm.customers.destroy', $c->id) }}" method="POST" class="inline" data-confirm="Bạn có chắc chắn muốn xóa lead {{ $c->name }} ({{ $c->code }})? Thao tác này không thể hoàn tác.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="p-1 rounded-lg hover:bg-rose-50 text-gray-400 hover:text-rose-600 transition cursor-pointer" title="Xóa Lead">
                                            <span class="material-symbols-outlined text-[18px]">delete</span>
                                        </button>
                                    </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-8 text-gray-400 text-xs">Không tìm thấy khách hàng nào phù hợp với điều kiện lọc.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>

            <x-pagination :paginator="$customers" />
        </div>
    </div>

    @push('scripts')
    <script>
        // Confirm xoá lead qua data-confirm (thay cho inline onsubmit — tránh XSS qua tên lead)
        document.addEventListener('submit', function (event) {
            const form = event.target instanceof Element ? event.target.closest('form[data-confirm]') : null;
            if (form && !window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        }, true);
    </script>
    @endpush
</x-app-layout>
