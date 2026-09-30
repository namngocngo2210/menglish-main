{{-- Luồng hội thoại ticket — dùng chung trang và modal. Biến: $ticket (messages.user), $asModal.
     data-ticket-messages: ô trả lời (ticket-reply.js) chèn bình luận vừa gửi vào cuối danh sách này. --}}
@php
    $asModal = $asModal ?? false;
@endphp
{{-- Messages Timeline --}}
<div class="space-y-4" data-ticket-messages>
    @foreach ($ticket->messages->reverse() as $msg)
        @include('support-tickets.partials.message')
    @endforeach
</div>
