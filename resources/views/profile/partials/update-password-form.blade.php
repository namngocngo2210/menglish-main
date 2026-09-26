<section>
    <header class="space-y-1">
        <h2 class="text-base font-bold text-on-surface">
            Đổi mật khẩu
        </h2>

        <p class="text-xs text-on-surface-variant">
            Đảm bảo tài khoản của bạn đang sử dụng mật khẩu dài và ngẫu nhiên để duy trì bảo mật
        </p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-5 space-y-4 text-xs">
        @csrf
        @method('put')

        <div>
            <x-ui.input id="update_password_current_password" name="current_password" type="password" :label="__('Mật khẩu hiện tại')" bag="updatePassword" autocomplete="current-password" />
        </div>

        <div>
            <x-ui.input id="update_password_password" name="password" type="password" :label="__('Mật khẩu mới')" bag="updatePassword" autocomplete="new-password" />
        </div>

        <div>
            <x-ui.input id="update_password_password_confirmation" name="password_confirmation" type="password" :label="__('Xác nhận mật khẩu mới')" bag="updatePassword" autocomplete="new-password" />
        </div>

        <div class="flex items-center gap-3 pt-2">
            <x-ui.button type="submit">
                Cập nhật mật khẩu
            </x-ui.button>

            @if (session('status') === 'password-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2500)"
                    class="text-xs text-tertiary font-bold flex items-center gap-1"
                >
                    <span class="material-symbols-outlined text-sm">check_circle</span>
                    <span>Đã đổi mật khẩu thành công!</span>
                </p>
            @endif
        </div>
    </form>
</section>
