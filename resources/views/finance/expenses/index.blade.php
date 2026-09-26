{{-- Mockup: ui-full-tinh-nang-menglish/epic-13-bao-cao-thu-chi/kho_n_chi_v_n_h_nh_menglish_admin --}}
<x-app-layout title="Sổ khoản chi vận hành">
    <x-ui.page-header title="Sổ khoản chi vận hành" icon="payments" description="Quản lý và ghi nhận các khoản chi phí hành chính, cơ sở vật chất và chi lương tự động">
        <x-slot:actions>
            <div class="hidden sm:flex items-center gap-2 px-3.5 py-2 bg-surface-container-low border border-surface-container-highest rounded-xl text-xs font-medium text-on-surface-variant">
                <span class="w-2 h-2 rounded-full bg-tertiary animate-pulse"></span>
                Quyền thao tác: <span class="font-semibold text-on-surface">Quản trị nhân sự &amp; Tài chính</span>
            </div>
            <x-ui.button icon="add_circle" onclick="openCreateModal()">Thêm khoản chi mới</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6">


        {{-- 1. Thống kê KPI tóm tắt 4 thẻ --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {{-- Thẻ 1: Tổng chi kỳ này --}}
            <div class="bg-surface-container-lowest p-5 rounded-2xl border border-surface-container-highest/80 shadow-xs relative overflow-hidden">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-on-surface-variant/70">Tổng chi kỳ này</span>
                    <span class="p-2 rounded-lg bg-primary-container/10 text-primary-container material-symbols-outlined text-[20px]">account_balance_wallet</span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-extrabold text-on-surface tracking-tight">
                        {{ number_format($grandTotalExpense, 0, ',', '.') }} <span class="text-sm font-semibold text-on-surface-variant/70">VNĐ</span>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1 text-xs text-on-surface-variant">
                        @if ($percentDiff != 0)
                            <span class="{{ $isDecreased ? 'text-tertiary' : 'text-error' }} font-semibold flex items-center">
                                <span class="material-symbols-outlined text-[14px]">{{ $isDecreased ? 'arrow_downward' : 'arrow_upward' }}</span>
                                {{ abs($percentDiff) }}%
                            </span>
                            <span>so với tháng {{ \Carbon\Carbon::createFromFormat('Y-m', $prevMonth)->format('m/Y') }}</span>
                        @else
                            <span class="text-on-surface-variant/70">Tương đương tháng trước</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Thẻ 2: Chi lương tự động (bảng lương) --}}
            <div class="bg-surface-container-lowest p-5 rounded-2xl border border-surface-container-highest/80 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-on-surface-variant/70">Chi lương tự động (bảng lương)</span>
                    <span class="p-2 rounded-lg bg-secondary/10 text-secondary material-symbols-outlined text-[20px]">badge</span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-extrabold text-secondary tracking-tight">
                        {{ number_format($autoSalaryAmount, 0, ',', '.') }} <span class="text-sm font-semibold text-on-surface-variant/70">VNĐ</span>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1 text-xs text-on-surface-variant">
                        @if ($autoSalaryAmount > 0)
                            <span class="inline-flex items-center gap-1 text-secondary font-medium">
                                <span class="material-symbols-outlined text-[14px]">sync_alt</span> Đã chốt &amp; Đã trả ({{ $autoSalaryStaffCount }} nhân sự)
                            </span>
                        @else
                            <span class="text-on-surface-variant/70 italic">Chưa có bảng lương chốt</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Thẻ 3: Chi phí vận hành tự nhập --}}
            <div class="bg-surface-container-lowest p-5 rounded-2xl border border-surface-container-highest/80 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-on-surface-variant/70">Chi phí vận hành tự nhập</span>
                    <span class="p-2 rounded-lg bg-tertiary/10 text-tertiary material-symbols-outlined text-[20px]">shopping_bag</span>
                </div>
                <div class="mt-3">
                    <div class="text-2xl font-extrabold text-on-surface tracking-tight">
                        {{ number_format($totalManualExpense, 0, ',', '.') }} <span class="text-sm font-semibold text-on-surface-variant/70">VNĐ</span>
                    </div>
                    <div class="flex items-center gap-1.5 mt-1 text-xs text-on-surface-variant">
                        <span>Tổng số <strong>{{ $manualExpensesCount }}</strong> phiếu chi tự nhập</span>
                    </div>
                </div>
            </div>

            {{-- Thẻ 4: Cơ cấu hình thức --}}
            <div class="bg-surface-container-lowest p-5 rounded-2xl border border-surface-container-highest/80 shadow-xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-on-surface-variant/70">Cơ cấu hình thức</span>
                    <span class="p-2 rounded-lg bg-info-container text-info material-symbols-outlined text-[20px]">credit_card</span>
                </div>
                <div class="mt-3">
                    <div class="flex items-center justify-between text-xs font-medium text-on-surface-variant">
                        <span>Chuyển khoản ({{ $transferPercent }}%)</span>
                        <span class="font-bold text-on-surface">{{ number_format($totalTransfer, 0, ',', '.') }}đ</span>
                    </div>
                    <div class="w-full bg-surface-container rounded-full h-2 mt-1.5 overflow-hidden flex">
                        <div class="bg-info h-full rounded-full transition-all" style="width: {{ $transferPercent }}%"></div>
                        <div class="bg-warning/70 h-full rounded-full transition-all" style="width: {{ $cashPercent }}%"></div>
                    </div>
                    <div class="flex items-center justify-between text-[11px] text-on-surface-variant/70 mt-1">
                        <span>Tiền mặt: {{ number_format($totalCash, 0, ',', '.') }}đ ({{ $cashPercent }}%)</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- 2. Thanh bộ lọc & Tìm kiếm --}}
        <div class="bg-surface-container-lowest p-4 rounded-2xl border border-surface-container-highest/80 shadow-xs">
            <form id="filterForm" method="GET" action="{{ route('finance.expenses.index') }}" class="flex flex-col md:flex-row items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
                    {{-- Bộ lọc Kỳ tháng --}}
                    <x-ui.select id="monthSelect" name="month" inline-label="Kỳ tháng:" onchange="this.form.submit()" :options="$monthOptions" :value="$month" />

                    {{-- Bộ lọc Chi nhánh --}}
                    <x-ui.select id="branchSelect" name="branch_id" inline-label="Chi nhánh:" onchange="this.form.submit()">
                        @unless ($branchScoped ?? false)<option value="all" {{ $branchId === 'all' ? 'selected' : '' }}>Tất cả chi nhánh</option>@endunless
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" {{ (string)$branchId === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </x-ui.select>

                    <div class="hidden lg:flex items-center pl-2 border-l border-surface-container-highest gap-1 text-xs text-on-surface-variant">
                        <span class="material-symbols-outlined text-[16px] text-on-surface-variant/70">info</span>
                        <span>Dòng lương tự động ẩn khi kỳ chưa có bảng lương chốt/trả</span>
                    </div>
                </div>

                {{-- Tìm kiếm & Xuất Excel --}}
                <div class="flex items-center gap-2 w-full md:w-auto justify-end">
                    <div class="w-full sm:w-64">
                        <x-ui.input name="search" icon="search" :value="$search" placeholder="Tìm theo nội dung, người lập..." />
                    </div>
                    <button type="submit" class="hidden"></button>
                    <x-ui.button variant="secondary" icon="download" :href="route('finance.expenses.export', ['month' => $month, 'branch_id' => $branchId, 'search' => $search])" title="Xuất dữ liệu Excel (CSV)" aria-label="Xuất dữ liệu Excel (CSV)" />
                </div>
            </form>
        </div>

        {{-- 3. Bảng dữ liệu các khoản chi --}}
        <x-ui.data-table>
            <x-slot:header>
                <div class="flex w-full items-center justify-between">
                    <div class="flex items-center gap-2">
                        <h2 class="font-bold text-on-surface">Danh sách các khoản chi</h2>
                        <x-ui.badge :pill="true" :dot="false">{{ $totalItemsCount }} khoản chi</x-ui.badge>
                    </div>
                    <div class="text-xs text-on-surface-variant flex items-center gap-1.5">
                        <span class="w-2.5 h-2.5 rounded-xs bg-secondary/10 border border-secondary/30"></span> Dòng tự động tổng hợp từ hệ thống Lương
                    </div>
                </div>
            </x-slot:header>

                <table>
                    <thead>
                        <tr>
                            <th>Ngày chi</th>
                            <th>Nội dung khoản chi</th>
                            <th class="text-right">Số tiền (VNĐ)</th>
                            <th class="text-center">Hình thức</th>
                            <th>Chi nhánh</th>
                            <th>Ghi chú &amp; Chứng từ</th>
                            <th class="text-center w-28">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>

                        {{-- DÒNG CHI LƯƠNG TỰ ĐỘNG (BẮT BUỘC KHÁC BIỆT VISUAL, KHÔNG CÓ SỬA/XÓA, ICON KHÓA + TOOLTIP) --}}
                        @if ($autoSalaryRow)
                            <tr class="bg-secondary/10 hover:bg-secondary/10 border-l-4 border-l-secondary">
                                <td class="font-semibold text-secondary whitespace-nowrap">
                                    <div class="flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-secondary text-[18px]">event_repeat</span>
                                        {{ $autoSalaryRow->expense_date }}
                                    </div>
                                    <div class="text-[11px] text-secondary font-normal pl-6">{{ $autoSalaryRow->date_sub }}</div>
                                </td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <x-ui.badge color="secondary" :dot="false">
                                            <span class="material-symbols-outlined text-[15px]">lock</span>
                                            TỰ ĐỘNG
                                        </x-ui.badge>
                                        <span class="font-bold text-on-surface text-[15px]">{{ $autoSalaryRow->title }}</span>
                                    </div>
                                    <p class="text-xs text-secondary/80 mt-1 flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[14px]">info</span>
                                        {{ $autoSalaryRow->description }}
                                    </p>
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <div class="text-base font-extrabold text-secondary">{{ number_format($autoSalaryRow->amount, 0, ',', '.') }}</div>
                                    <div class="text-[11px] text-on-surface-variant">{{ $autoSalaryRow->staff_count }} nhân sự đủ điều kiện</div>
                                </td>
                                <td class="text-center">
                                    <x-ui.badge color="info" :pill="true" :dot="false">
                                        <span class="material-symbols-outlined text-[14px]">account_balance</span>
                                        Chuyển khoản
                                    </x-ui.badge>
                                </td>
                                <td class="whitespace-nowrap">
                                    <x-ui.badge :dot="false">
                                        {{ $autoSalaryRow->branch_name }}
                                    </x-ui.badge>
                                </td>
                                <td>
                                    <div class="text-xs text-on-surface-variant max-w-xs truncate" title="{{ $autoSalaryRow->notes }}">
                                        {{ $autoSalaryRow->notes }}
                                    </div>
                                </td>
                                {{-- Thao tác: KHÔNG CÓ NÚT SỬA/XÓA - THAY BẰNG BADGE KHÓA CỐ ĐỊNH --}}
                                <td class="text-center">
                                    <div class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-surface-container border border-surface-container-highest text-on-surface-variant text-xs font-medium cursor-help" title="Số liệu tự động từ bảng lương đã chốt — không sửa được tại đây">
                                        <span class="material-symbols-outlined text-[16px] text-on-surface-variant/70">lock</span>
                                        <span>Cố định</span>
                                    </div>
                                </td>
                            </tr>
                        @endif

                        {{-- CÁC DÒNG CHI TỰ NHẬP (CÓ ĐỦ NÚT SỬA / XÓA) --}}
                        @forelse ($manualExpenses as $exp)
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="text-on-surface-variant whitespace-nowrap font-medium">
                                    {{ \Carbon\Carbon::parse($exp->expense_date)->format('d/m/Y') }}
                                </td>
                                <td>
                                    <div class="font-semibold text-on-surface">{{ $exp->title }}</div>
                                    <div class="text-xs text-on-surface-variant/70">
                                        Người lập: {{ $exp->creator?->name ?? '—' }} • {{ $exp->created_at ? $exp->created_at->format('d/m H:i') : '' }}
                                    </div>
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <x-ui.money :value="$exp->amount" suffix="" class="font-bold" />
                                </td>
                                <td class="text-center">
                                    @if ($exp->payment_method === 'chuyen_khoan')
                                        <x-ui.badge color="info" :pill="true" :dot="false">
                                            <span class="material-symbols-outlined text-[13px]">account_balance</span>
                                            Chuyển khoản
                                        </x-ui.badge>
                                    @else
                                        <x-ui.badge color="warning" :pill="true" :dot="false">
                                            <span class="material-symbols-outlined text-[13px]">payments</span>
                                            Tiền mặt
                                        </x-ui.badge>
                                    @endif
                                </td>
                                <td class="text-on-surface-variant whitespace-nowrap">
                                    {{ $exp->branch?->name ?? 'Toàn hệ thống' }}
                                </td>
                                <td class="text-xs text-on-surface-variant">
                                    {{ $exp->notes ?: '—' }}
                                </td>
                                <td class="text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <x-ui.button variant="ghost" size="sm" icon="edit" onclick="openEditModal({{ json_encode($exp) }})" title="Sửa khoản chi" aria-label="Sửa khoản chi" />
                                        <form method="POST" action="{{ route('finance.expenses.destroy', $exp->id) }}" onsubmit="return confirm('Bạn có chắc chắn muốn xóa khoản chi: \'{{ addslashes($exp->title) }}\' khỏi sổ chi vận hành?')" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button type="submit" variant="ghost" size="sm" icon="delete" title="Xóa khoản chi" aria-label="Xóa khoản chi" />
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            @if (!$autoSalaryRow)
                                <tr>
                                    <td colspan="7">
                                        <x-ui.empty-state icon="receipt_long" title="Chưa có khoản chi nào trong kỳ tháng này" description="Bấm &quot;+ Thêm khoản chi mới&quot; để ghi nhận chi phí" />
                                    </td>
                                </tr>
                            @endif
                        @endforelse

                    </tbody>
                </table>

            {{-- Footer: Tổng kết --}}
            <x-slot:footer>
                <div class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-on-surface-variant">
                    <div class="flex items-center gap-2">
                        <span>Hiển thị toàn bộ <strong>{{ $totalItemsCount }}</strong> bản ghi của tháng</span>
                        <span class="text-on-surface-variant/70">|</span>
                        <span class="text-on-surface-variant">Tổng cộng thực chi: <strong class="text-on-surface text-sm">{{ number_format($grandTotalExpense, 0, ',', '.') }} VNĐ</strong></span>
                    </div>
                </div>
            </x-slot:footer>
        </x-ui.data-table>

    </div>

    {{-- MODAL THÊM / SỬA KHOẢN CHI (FORM ĐẦY ĐỦ 6 Ô THEO SPEC) — tiêu đề đổi bằng JS qua id modal-expense-modal-title --}}
    <x-ui.modal name="expense-modal" title="Thêm khoản chi vận hành mới" max-width="lg">
        <p class="mb-4 text-xs text-on-surface-variant">Lưu ngay vào sổ chi, không cần duyệt</p>

        {{-- Modal Form Body --}}
        <form id="expenseForm" method="POST" action="{{ route('finance.expenses.store') }}" class="space-y-4">
            @csrf
            <input type="hidden" id="formMethod" name="_method" value="POST">

            {{-- Ô 1: Ngày chi (date, bắt buộc) --}}
            <x-ui.date id="formExpenseDate" name="expense_date" label="Ngày chi" :value="date('Y-m-d')" required />

            {{-- Ô 2: Nội dung (text tự do, bắt buộc) --}}
            <x-ui.input id="formTitle" name="title" label="Nội dung khoản chi" placeholder="Ví dụ: Mua rèm cửa phòng học, Nạp mực máy in..." required />

            {{-- Grid 2 cột: Số tiền & Hình thức --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {{-- Ô 3: Số tiền (number > 0, bắt buộc) — giữ input thô để có hậu tố "đ" --}}
                <x-ui.field label="Số tiền (VNĐ)" name="amount" for="formAmount" required>
                    <div class="relative">
                        <input type="number" id="formAmount" name="amount" min="1000" step="1000" placeholder="0" required class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest pl-md pr-10 py-sm font-body-base text-body-base font-semibold text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-on-surface-variant/70">đ</span>
                    </div>
                </x-ui.field>

                {{-- Ô 4: Hình thức chi (dropdown Tiền mặt/Chuyển khoản) --}}
                <x-ui.select id="formPaymentMethod" name="payment_method" label="Hình thức chi" required :value="''"
                             :options="['chuyen_khoan' => 'Chuyển khoản', 'tien_mat' => 'Tiền mặt']" />
            </div>

            {{-- Ô 5: Chi nhánh (dropdown, bắt buộc) --}}
            <x-ui.select id="formBranchId" name="branch_id" label="Chi nhánh áp dụng" required :value="''">
                <option value="" disabled selected>-- Chọn chi nhánh cơ sở --</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                @endforeach
            </x-ui.select>

            {{-- Phân loại danh mục (Tùy chọn) --}}
            <x-ui.select id="formCategory" name="category" label="Phân loại chi phí" hint="(Tự động nhận diện nếu để trống)" :value="''"
                         :options="['' => 'Tự động theo nội dung', 'mat_bang_tien_ich' => 'Mặt bằng & Tiện ích (Thuê nhà, điện, nước, internet...)', 'giao_trinh_van_hanh' => 'In ấn & Vận hành lớp (Giáo trình, VPP, điều hòa, nước uống...)', 'khac' => 'Chi phí khác']" />

            {{-- Ô 6: Ghi chú (textarea, optional) --}}
            <x-ui.textarea id="formNotes" name="notes" label="Ghi chú & Thông tin chứng từ" hint="(Tùy chọn)" rows="3" placeholder="Nhập mã hóa đơn, thông tin nhà cung cấp hoặc lưu ý nội bộ..." class="resize-none" />

            {{-- Thông tin tự động --}}
            <div class="p-3 bg-surface-container-low rounded-xl border border-surface-container-highest/60 text-xs text-on-surface-variant flex items-center justify-between">
                <span>Người lập: <strong class="text-on-surface-variant">{{ Auth::user()->name ?? 'Admin' }}</strong></span>
                <span>Thời điểm: <strong class="text-on-surface-variant">Tự động khi lưu</strong></span>
            </div>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" onclick="closeModal()">
                Hủy bỏ
            </x-ui.button>
            <x-ui.button type="submit" form="expenseForm" icon="save">
                <span id="submitBtnText">Lưu khoản chi</span>
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <script>
        const expenseForm = document.getElementById('expenseForm');
        const formMethod = document.getElementById('formMethod');
        const modalTitle = document.getElementById('modal-expense-modal-title');
        const submitBtnText = document.getElementById('submitBtnText');

        function openCreateModal() {
            expenseForm.reset();
            formMethod.value = 'POST';
            expenseForm.action = "{{ route('finance.expenses.store') }}";
            modalTitle.innerText = 'Thêm khoản chi vận hành mới';
            submitBtnText.innerText = 'Lưu khoản chi';
            document.getElementById('formExpenseDate').value = "{{ date('Y-m-d') }}";
            window.dispatchEvent(new CustomEvent('open-modal', { detail: 'expense-modal' }));
        }

        function openEditModal(exp) {
            expenseForm.reset();
            formMethod.value = 'PUT';
            expenseForm.action = "/finance/expenses/" + exp.id;
            modalTitle.innerText = 'Chỉnh sửa khoản chi vận hành';
            submitBtnText.innerText = 'Cập nhật khoản chi';

            document.getElementById('formExpenseDate').value = exp.expense_date ? exp.expense_date.substring(0, 10) : '';
            document.getElementById('formTitle').value = exp.title || '';
            document.getElementById('formAmount').value = exp.amount ? parseInt(exp.amount) : '';
            document.getElementById('formPaymentMethod').value = exp.payment_method || 'chuyen_khoan';
            document.getElementById('formBranchId').value = exp.branch_id || '';
            document.getElementById('formCategory').value = exp.category || '';
            document.getElementById('formNotes').value = exp.notes || '';

            window.dispatchEvent(new CustomEvent('open-modal', { detail: 'expense-modal' }));
        }

        function closeModal() {
            window.dispatchEvent(new CustomEvent('close-modal', { detail: 'expense-modal' }));
        }
    </script>
</x-app-layout>
