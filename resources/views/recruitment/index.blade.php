<x-app-layout>
    <x-ui.page-header title="Quản lý Tuyển dụng & Hồ sơ Ứng viên">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="open_in_new" :href="route('portal.recruitment')" target="_blank">Cổng nộp CV Online</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6" x-data="{ showNewJobModal: false }">
        

        {{-- 4 Metric Cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">work</span>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500">Tin tuyển dụng đang mở</span>
                    <p class="text-2xl font-extrabold text-gray-900">{{ $totalJobs }} <span class="text-xs text-gray-400 font-normal">vị trí</span></p>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-orange-50 text-primary flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">description</span>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500">Tổng CV tiếp nhận</span>
                    <p class="text-2xl font-extrabold text-primary">{{ $totalCvs }} <span class="text-xs text-gray-400 font-normal">hồ sơ</span></p>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">contact_phone</span>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500">Đã phỏng vấn</span>
                    <p class="text-2xl font-extrabold text-purple-600">{{ $interviewedCount }} <span class="text-xs text-purple-400 font-normal">ứng viên</span></p>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">how_to_reg</span>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500">Trúng tuyển / Nhận việc</span>
                    <p class="text-2xl font-extrabold text-emerald-600">{{ $acceptedCount }} <span class="text-xs text-emerald-400 font-normal">nhân sự</span></p>
                </div>
            </div>
        </div>

        {{-- Tabs & Filters --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-xs overflow-hidden">
            <div class="border-b border-gray-200 p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <a href="{{ route('recruitment.index', ['tab' => 'candidates']) }}"
                       class="px-4 py-2 rounded-xl text-xs font-bold transition whitespace-nowrap {{ $tab === 'candidates' ? 'bg-primary-container text-white shadow-xs' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                        1. Danh sách Hồ sơ CV ({{ $totalCvs }})
                    </a>
                    <a href="{{ route('recruitment.index', ['tab' => 'jobs']) }}"
                       class="px-4 py-2 rounded-xl text-xs font-bold transition whitespace-nowrap {{ $tab === 'jobs' ? 'bg-primary-container text-white shadow-xs' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                        2. Tin Tuyển dụng ({{ $jobs->count() }})
                    </a>
                </div>

                @if($tab === 'jobs')
                    <button type="button" @click="showNewJobModal = true" class="px-3.5 py-2 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-xs transition flex items-center gap-1.5 self-start sm:self-auto">
                        <span class="material-symbols-outlined text-[18px]">add_circle</span>
                        <span>Đăng tin tuyển dụng</span>
                    </button>
                @endif
            </div>

            @if($tab === 'candidates')
                {{-- Filter Status & Branch --}}
                <div class="p-4 bg-gray-50/70 border-b border-gray-100 flex flex-wrap items-center gap-3">
                    <form method="GET" action="{{ route('recruitment.index') }}" class="flex flex-wrap items-center gap-3 w-full sm:w-auto">
                        <input type="hidden" name="tab" value="candidates">
                        <select name="status" onchange="this.form.submit()" class="text-xs rounded-xl border-gray-200 py-1.5 px-3 font-medium">
                            <option value="all" {{ $status === 'all' ? 'selected' : '' }}>Tất cả trạng thái</option>
                            <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Chờ xử lý</option>
                            <option value="reviewing" {{ $status === 'reviewing' ? 'selected' : '' }}>Đang đánh giá</option>
                            <option value="interviewed" {{ $status === 'interviewed' ? 'selected' : '' }}>Đã phỏng vấn</option>
                            <option value="accepted" {{ $status === 'accepted' ? 'selected' : '' }}>Đã tuyển dụng</option>
                            <option value="rejected" {{ $status === 'rejected' ? 'selected' : '' }}>Từ chối</option>
                        </select>

                        <select name="branch_id" onchange="this.form.submit()" class="text-xs rounded-xl border-gray-200 py-1.5 px-3 font-medium">
                            <option value="">Tất cả cơ sở</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ $branchId == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>

                {{-- Table CVs --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-gray-50 text-gray-600 font-bold uppercase tracking-wider border-b border-gray-200">
                            <tr>
                                <th class="py-3 px-4">Ứng viên</th>
                                <th class="py-3 px-4">Vị trí & Cơ sở</th>
                                <th class="py-3 px-4">Hồ sơ CV / Portfolio</th>
                                <th class="py-3 px-4">Trạng thái</th>
                                <th class="py-3 px-4">Ghi chú tuyển dụng</th>
                                <th class="py-3 px-4 text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($candidates as $can)
                                @php $badge = $can->statusBadge(); @endphp
                                <tr class="hover:bg-gray-50/80 transition" x-data="{ openEdit: false }">
                                    <td class="py-3.5 px-4">
                                        <span class="font-bold text-gray-900 text-sm block">{{ $can->full_name }}</span>
                                        <div class="flex items-center gap-2 text-[11px] text-gray-500 mt-0.5">
                                            <span>{{ $can->phone }}</span>
                                            <span>•</span>
                                            <span>{{ $can->email }}</span>
                                        </div>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <span class="font-semibold text-primary block">{{ $can->applying_position }}</span>
                                        <span class="text-[11px] text-gray-500">{{ $can->branch?->name ?? 'Mọi chi nhánh' }}</span>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        @if($can->cv_file_path)
                                            <a href="{{ asset('storage/' . $can->cv_file_path) }}" target="_blank" class="inline-flex items-center gap-1 text-blue-600 hover:underline font-bold">
                                                <span class="material-symbols-outlined text-[16px]">attach_file</span>
                                                Xem file CV
                                            </a>
                                        @else
                                            <span class="text-gray-400">Không đính kèm file</span>
                                        @endif
                                        @if($can->portfolio_url)
                                            <a href="{{ $can->portfolio_url }}" target="_blank" class="block text-[11px] text-purple-600 hover:underline font-medium mt-0.5">
                                                Link Video / Portfolio &rarr;
                                            </a>
                                        @endif
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border {{ $badge['class'] }}">
                                            {{ $badge['label'] }}
                                        </span>
                                    </td>
                                    <td class="py-3.5 px-4 max-w-xs">
                                        <p class="text-[11px] text-gray-600 line-clamp-2 whitespace-pre-line">{{ $can->notes ?: 'Chưa có ghi chú' }}</p>
                                    </td>
                                    <td class="py-3.5 px-4 text-right">
                                        <button type="button" @click="openEdit = !openEdit" class="px-2.5 py-1.5 rounded-lg border border-gray-300 hover:bg-gray-100 text-gray-700 font-semibold text-[11px] transition">
                                            Cập nhật
                                        </button>

                                        {{-- Dropdown Update Status Modal/Popover --}}
                                        <div x-show="openEdit" x-cloak class="mt-2 p-3 bg-white border border-gray-200 rounded-xl shadow-lg text-left space-y-2 w-64 absolute right-4 z-20">
                                            <form action="{{ route('recruitment.cv.update-status', $can->id) }}" method="POST" class="space-y-2">
                                                @csrf
                                                <label class="block text-[10px] font-bold text-gray-700 uppercase">Trạng thái mới</label>
                                                <select name="status" class="w-full text-xs rounded-lg border-gray-200 p-1.5 font-semibold">
                                                    <option value="pending" {{ $can->status === 'pending' ? 'selected' : '' }}>Chờ xử lý</option>
                                                    <option value="reviewing" {{ $can->status === 'reviewing' ? 'selected' : '' }}>Đang đánh giá</option>
                                                    <option value="interviewed" {{ $can->status === 'interviewed' ? 'selected' : '' }}>Đã phỏng vấn</option>
                                                    <option value="accepted" {{ $can->status === 'accepted' ? 'selected' : '' }}>Đã tuyển dụng</option>
                                                    <option value="rejected" {{ $can->status === 'rejected' ? 'selected' : '' }}>Từ chối</option>
                                                </select>
                                                <label class="block text-[10px] font-bold text-gray-700 uppercase">Ghi chú</label>
                                                <textarea name="notes" rows="2" placeholder="Ghi chú đánh giá, lịch hẹn PV..." class="w-full text-xs rounded-lg border-gray-200 p-1.5"></textarea>
                                                <div class="flex items-center justify-end gap-1.5 pt-1">
                                                    <button type="button" @click="openEdit = false" class="px-2 py-1 text-xs text-gray-500 hover:bg-gray-100 rounded">Đóng</button>
                                                    <button type="submit" class="px-3 py-1 bg-primary-container text-white text-xs font-bold rounded-lg shadow-xs">Lưu</button>
                                                </div>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="p-8 text-center text-gray-400">
                                        <span class="material-symbols-outlined text-4xl mb-1 text-gray-300">inbox</span>
                                        <p class="font-medium text-xs text-gray-500">Chưa có hồ sơ ứng viên nào trong mục này.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-t border-gray-100">
                    {{ $candidates->links() }}
                </div>
            @endif

            @if($tab === 'jobs')
                {{-- Table Jobs --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-gray-50 text-gray-600 font-bold uppercase tracking-wider border-b border-gray-200">
                            <tr>
                                <th class="py-3 px-4">Vị trí tuyển dụng</th>
                                <th class="py-3 px-4">Bộ phận / Hình thức</th>
                                <th class="py-3 px-4">Cơ sở / Địa điểm</th>
                                <th class="py-3 px-4">Mức lương</th>
                                <th class="py-3 px-4">Số CV nộp</th>
                                <th class="py-3 px-4">Trạng thái</th>
                                <th class="py-3 px-4 text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($jobs as $job)
                                <tr class="hover:bg-gray-50/80 transition">
                                    <td class="py-3.5 px-4 font-bold text-gray-900 text-sm">
                                        {{ $job->title }}
                                        <span class="block text-[11px] text-gray-500 font-normal">Hạn nộp: {{ $job->deadline ? $job->deadline->format('d/m/Y') : 'Không thời hạn' }}</span>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <span class="font-semibold text-gray-800">{{ $job->department }}</span>
                                        <span class="block text-[10px] text-gray-500">{{ $job->employment_type }}</span>
                                    </td>
                                    <td class="py-3.5 px-4 text-gray-700">
                                        {{ $job->branch?->name ?? 'Toàn hệ thống' }}
                                    </td>
                                    <td class="py-3.5 px-4 font-semibold text-emerald-600">
                                        {{ $job->salary_range ?: 'Thỏa thuận' }}
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-blue-50 text-blue-700">
                                            {{ $job->candidate_cvs_count }} CV
                                        </span>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        @if($job->is_active)
                                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                Đang mở nhận CV
                                            </span>
                                        @else
                                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-gray-100 text-gray-600 border border-gray-200">
                                                Đã đóng
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-3.5 px-4 text-right">
                                        <form action="{{ route('recruitment.jobs.toggle', $job->id) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="px-3 py-1 rounded-lg border border-gray-300 hover:bg-gray-100 text-gray-700 font-semibold text-[11px] transition">
                                                {{ $job->is_active ? 'Đóng tin' : 'Mở lại' }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="p-8 text-center text-gray-400">
                                        <p class="font-medium text-xs text-gray-500">Chưa có tin tuyển dụng nào.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Modal Đăng tin tuyển dụng --}}
        <div x-show="showNewJobModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-black/50 flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl max-w-xl w-full p-6 space-y-4 shadow-xl">
                <div class="flex items-center justify-between border-b pb-3">
                    <h3 class="font-bold text-base text-gray-900">Đăng tin tuyển dụng mới</h3>
                    <button type="button" @click="showNewJobModal = false" class="text-gray-400 hover:text-gray-600">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>

                <form action="{{ route('recruitment.jobs.store') }}" method="POST" class="space-y-3 text-xs">
                    @csrf
                    <div>
                        <label class="block font-bold text-gray-700 mb-1">Tiêu đề vị trí tuyển dụng <span class="text-rose-500">*</span></label>
                        <input type="text" name="title" required placeholder="Ví dụ: Giáo viên Tiếng Anh Giao Tiếp Full-time" class="w-full text-xs rounded-xl border-gray-200 p-2.5">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-gray-700 mb-1">Bộ phận / Khối <span class="text-rose-500">*</span></label>
                            <select name="department" required class="w-full text-xs rounded-xl border-gray-200 p-2.5">
                                <option value="Học thuật & Đào tạo">Học thuật & Đào tạo</option>
                                <option value="Học vụ & Vận hành">Học vụ & Vận hành</option>
                                <option value="Tuyển sinh & CRM">Tuyển sinh & CRM</option>
                                <option value="Marketing & Sự kiện">Marketing & Sự kiện</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-gray-700 mb-1">Hình thức làm việc</label>
                            <select name="employment_type" required class="w-full text-xs rounded-xl border-gray-200 p-2.5">
                                <option value="Full-time">Full-time</option>
                                <option value="Part-time">Part-time</option>
                                <option value="Thực tập">Thực tập</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-gray-700 mb-1">Cơ sở làm việc</label>
                            <select name="branch_id" class="w-full text-xs rounded-xl border-gray-200 p-2.5">
                                <option value="">Toàn hệ thống</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-gray-700 mb-1">Mức lương dự kiến</label>
                            <input type="text" name="salary_range" placeholder="Ví dụ: 12 - 18 triệu hoặc 300k/giờ" class="w-full text-xs rounded-xl border-gray-200 p-2.5">
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-gray-700 mb-1">Mô tả công việc <span class="text-rose-500">*</span></label>
                        <textarea name="description" rows="3" required placeholder="Nêu chi tiết nhiệm vụ chính..." class="w-full text-xs rounded-xl border-gray-200 p-2.5"></textarea>
                    </div>

                    <div>
                        <label class="block font-bold text-gray-700 mb-1">Yêu cầu ứng viên</label>
                        <textarea name="requirements" rows="2" placeholder="IELTS 7.0+, phát âm chuẩn, nhiệt huyết..." class="w-full text-xs rounded-xl border-gray-200 p-2.5"></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t">
                        <button type="button" @click="showNewJobModal = false" class="px-4 py-2 border rounded-xl text-gray-600 hover:bg-gray-50">Hủy</button>
                        <button type="submit" class="px-5 py-2 bg-primary-container text-white font-bold rounded-xl shadow-sm hover:bg-primary-hover">Đăng tin ngay</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
