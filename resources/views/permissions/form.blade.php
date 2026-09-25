<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-900">{{ $permission->exists ? 'Sửa permission' : 'Thêm permission' }}</h2>
    </x-slot>

    <div class="max-w-md mx-auto bg-white border border-gray-200 rounded-xl p-6 shadow-sm">
        <form method="POST" action="{{ $permission->exists ? route('permissions.update', $permission) : route('permissions.store') }}" class="space-y-4">
            @csrf
            @if ($permission->exists) @method('PUT') @endif

            <div>
                <x-input-label for="name" value="Tên permission (module.action) *" />
                <x-text-input id="name" name="name" class="block mt-1 w-full font-mono" placeholder="vd: report.export" :value="old('name', $permission->name)" required />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>

            <div class="pt-4 flex justify-end gap-3">
                <a href="{{ route('permissions.index') }}" class="px-4 py-2 rounded-lg border border-gray-300 text-gray-700 text-sm">Hủy</a>
                <button type="submit" class="px-4 py-2 rounded-lg bg-primary-container hover:bg-primary-hover text-white text-sm font-medium">Lưu permission</button>
            </div>
        </form>
    </div>
</x-app-layout>
