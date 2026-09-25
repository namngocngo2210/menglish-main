{{-- Danh sách lỗi validate của form học phí (trang dùng <x-app-layout hide-errors> để không lặp alert toàn cục). --}}
@if ($errors->any())
    <x-ui.alert type="error" title="Vui lòng kiểm tra lại thông tin" class="mb-4">
        <ul class="list-inside list-disc space-y-0.5">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </x-ui.alert>
@endif
