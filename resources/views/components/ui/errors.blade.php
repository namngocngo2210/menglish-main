{{--
    <x-ui.errors> — danh sách lỗi validate cùng kiểu với lỗi của <x-ui.field>, dùng khi 1 control gom lỗi của nhiều khoá
    (vd. class_id + task_id, mảng board_images.*) mà field không tự hiển thị được.
    Props: messages (chuỗi | mảng lỗi; rỗng => không render gì)
    Ví dụ: <x-ui.errors :messages="array_merge($errors->get('date_from'), $errors->get('date_to'))" />
--}}
@props(['messages' => []])

@if (($messages = array_values(array_filter((array) $messages))) !== [])
    <ul {{ $attributes->merge(['class' => 'space-y-xs']) }} role="alert">
        @foreach ($messages as $message)
            <li class="flex items-center gap-xs font-caption text-caption text-error">
                <span class="material-symbols-outlined text-[14px]" aria-hidden="true">error</span>{{ $message }}
            </li>
        @endforeach
    </ul>
@endif
