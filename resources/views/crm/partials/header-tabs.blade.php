<div class="border-b border-gray-200 bg-white -mt-4 -mx-4 sm:-mt-6 sm:-mx-6 px-6 pt-4 mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 pb-3">
        <div>
            <h1 class="text-2xl font-black text-gray-900 tracking-tight">Quản lý tuyển sinh</h1>
        </div>

        <div class="flex items-center gap-3">
            <div class="relative w-64 sm:w-80">
                <span class="material-symbols-outlined absolute left-3 top-2 text-gray-400 text-base">search</span>
                <input 
                    type="text" 
                    placeholder="Tìm kiếm khách hàng..." 
                    class="w-full pl-9 pr-3 py-1.5 text-xs rounded-xl border border-gray-200 focus:outline-none focus:border-primary-container focus:ring-1 focus:ring-primary-container bg-gray-50/70"
                    onkeydown="if(event.key === 'Enter') { window.location.href = '{{ route('crm.customers.index') }}?search=' + encodeURIComponent(this.value); }"
                />
            </div>

            <a href="{{ route('crm.customers.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary-container hover:bg-primary text-white text-xs font-bold shadow-sm transition shrink-0">
                <span class="material-symbols-outlined text-[18px]">add</span>
                <span>Thêm khách mới</span>
            </a>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex items-center gap-6 overflow-x-auto text-xs font-semibold scrollbar-none pt-1">
        <a href="{{ route('crm.pipeline') }}" class="pb-3 border-b-2 transition whitespace-nowrap {{ request()->routeIs('crm.pipeline') ? 'border-primary-container text-primary-container font-bold' : 'border-transparent text-gray-600 hover:text-gray-900' }}">
            Theo giai đoạn
        </a>
        <a href="{{ route('crm.customers.index') }}" class="pb-3 border-b-2 transition whitespace-nowrap {{ request()->routeIs('crm.customers.index') ? 'border-primary-container text-primary-container font-bold' : 'border-transparent text-gray-600 hover:text-gray-900' }}">
            Danh sách
        </a>
        <a href="{{ route('crm.waiting-list') }}" class="pb-3 border-b-2 transition whitespace-nowrap {{ request()->routeIs('crm.waiting-list') ? 'border-primary-container text-primary-container font-bold' : 'border-transparent text-gray-600 hover:text-gray-900' }}">
            Danh sách chờ lớp
        </a>
        <a href="{{ route('crm.reports') }}" class="pb-3 border-b-2 transition whitespace-nowrap {{ request()->routeIs('crm.reports') ? 'border-primary-container text-primary-container font-bold' : 'border-transparent text-gray-600 hover:text-gray-900' }}">
            Báo cáo doanh số
        </a>
        <a href="{{ route('crm.customers.won') }}" class="pb-3 border-b-2 transition whitespace-nowrap {{ request()->routeIs('crm.customers.won') ? 'border-primary-container text-primary-container font-bold' : 'border-transparent text-gray-600 hover:text-gray-900' }}">
            Khách chốt thành công
        </a>
        <a href="{{ route('crm.lost-deals') }}" class="pb-3 border-b-2 transition whitespace-nowrap {{ request()->routeIs('crm.lost-deals') ? 'border-primary-container text-primary-container font-bold' : 'border-transparent text-gray-600 hover:text-gray-900' }}">
            Khách không chốt
        </a>
        <a href="{{ route('placement-tests.index') }}" class="pb-3 border-b-2 transition whitespace-nowrap {{ request()->routeIs('placement-tests.index') ? 'border-primary-container text-primary-container font-bold' : 'border-transparent text-gray-600 hover:text-gray-900' }}">
            Đề Test đầu vào (AI)
        </a>
        <a href="{{ route('placement-tests.rubric-guide') }}" class="pb-3 border-b-2 transition whitespace-nowrap {{ request()->routeIs('placement-tests.rubric-guide') ? 'border-primary-container text-primary-container font-bold' : 'border-transparent text-gray-600 hover:text-gray-900' }}">
            Cổng Test (Học viên)
        </a>
    </div>
</div>
