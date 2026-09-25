<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-900">{{ $category->exists ? 'Sửa danh mục' : 'Thêm danh mục mới' }}</h2>
    </x-slot>

    <div class="max-w-md mx-auto bg-white border border-gray-200 rounded-xl p-6 shadow-sm">
        <form method="POST" action="{{ $category->exists ? route('system-categories.update', $category) : route('system-categories.store') }}" class="space-y-4">
            @csrf
            @if ($category->exists) @method('PUT') @endif

            <div>
                <x-input-label for="type" value="Loại danh mục *" />
                <select id="type" name="type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @foreach ($types as $t)
                        <option value="{{ $t }}" @selected(old('type', $category->type) === $t)>{{ $t }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('type')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="code" value="Mã danh mục *" />
                <x-text-input id="code" name="code" class="block mt-1 w-full" :value="old('code', $category->code)" required />
                <x-input-error :messages="$errors->get('code')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="name" value="Tên danh mục *" />
                <x-text-input id="name" name="name" class="block mt-1 w-full" :value="old('name', $category->name)" required />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="sort_order" value="Thứ tự hiển thị" />
                <x-text-input id="sort_order" type="number" name="sort_order" class="block mt-1 w-full" :value="old('sort_order', $category->sort_order ?? 0)" />
                <x-input-error :messages="$errors->get('sort_order')" class="mt-1" />
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category->is_active ?? true)) class="rounded border-gray-300 text-primary focus:ring-primary-container">
                Đang sử dụng
            </label>

            <div class="pt-4 flex justify-end gap-3">
                <a href="{{ route('system-categories.index') }}" class="px-4 py-2 rounded-lg border border-gray-300 text-gray-700 text-sm">Hủy</a>
                <button type="submit" class="px-4 py-2 rounded-lg bg-primary-container hover:bg-primary-hover text-white text-sm font-medium">Lưu thông tin</button>
            </div>
        </form>
    </div>
</x-app-layout>
