<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-900">{{ $holiday->exists ? 'Sửa ngày nghỉ' : 'Thêm ngày nghỉ mới' }}</h2>
    </x-slot>

    <div class="max-w-lg mx-auto bg-white border border-gray-200 rounded-xl p-6 shadow-sm">
        <form method="POST" action="{{ $holiday->exists ? route('holidays.update', $holiday) : route('holidays.store') }}" class="space-y-4" x-data="{ systemWide: {{ old('is_system_wide', $holiday->is_system_wide ?? true) ? 'true' : 'false' }} }">
            @csrf
            @if ($holiday->exists) @method('PUT') @endif

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="code" value="Mã ngày nghỉ *" />
                    <x-text-input id="code" name="code" class="block mt-1 w-full" :value="old('code', $holiday->code)" required />
                    <x-input-error :messages="$errors->get('code')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="name" value="Tên ngày nghỉ *" />
                    <x-text-input id="name" name="name" class="block mt-1 w-full" :value="old('name', $holiday->name)" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="start_date" value="Từ ngày *" />
                    <x-text-input id="start_date" type="date" name="start_date" class="block mt-1 w-full" :value="old('start_date', optional($holiday->start_date)->format('Y-m-d'))" required />
                    <x-input-error :messages="$errors->get('start_date')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="end_date" value="Đến ngày *" />
                    <x-text-input id="end_date" type="date" name="end_date" class="block mt-1 w-full" :value="old('end_date', optional($holiday->end_date)->format('Y-m-d'))" required />
                    <x-input-error :messages="$errors->get('end_date')" class="mt-1" />
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_system_wide" value="1" x-model="systemWide" class="rounded border-gray-300 text-primary focus:ring-primary">
                Áp dụng toàn hệ thống
            </label>

            <div x-show="!systemWide" x-cloak>
                <x-input-label value="Chi nhánh áp dụng" />
                <div class="grid grid-cols-2 gap-1 mt-1">
                    @foreach ($branches as $branch)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" @checked(in_array($branch->id, old('branch_ids', $selectedBranchIds))) class="rounded border-gray-300 text-primary focus:ring-primary">
                            {{ $branch->name }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="pt-4 flex justify-end gap-3">
                <a href="{{ route('holidays.index') }}" class="px-4 py-2 rounded-lg border border-gray-300 text-gray-700 text-sm">Hủy</a>
                <button type="submit" class="px-4 py-2 rounded-lg bg-primary hover:bg-primary-hover text-white text-sm font-medium">Lưu thông tin</button>
            </div>
        </form>
    </div>
</x-app-layout>
