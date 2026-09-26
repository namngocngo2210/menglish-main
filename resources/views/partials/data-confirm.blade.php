{{--
    Hộp xác nhận cho <form data-confirm="..."> (thay cho onsubmit inline — tránh chèn dữ liệu vào JS).
    @once: include nhiều lần trên cùng trang vẫn chỉ gắn 1 listener.
--}}
@once
    @push('scripts')
        <script>
            if (! window.__menglishDataConfirm) {
                window.__menglishDataConfirm = true;
                document.addEventListener('submit', function (event) {
                    const form = event.target instanceof Element ? event.target.closest('form[data-confirm]') : null;
                    if (form && ! window.confirm(form.getAttribute('data-confirm'))) {
                        event.preventDefault();
                    }
                }, true);
            }
        </script>
    @endpush
@endonce
