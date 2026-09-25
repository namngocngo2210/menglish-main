<section>
    <header class="space-y-1">
        <h2 class="text-base font-bold text-gray-900">
            Đổi mật khẩu
        </h2>

        <p class="text-xs text-gray-500">
            Đảm bảo tài khoản của bạn đang sử dụng mật khẩu dài và ngẫu nhiên để duy trì bảo mật
        </p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-5 space-y-4 text-xs">
        @csrf
        @method('put')

        <div>
            <x-input-label for="update_password_current_password" :value="__('Mật khẩu hiện tại')" class="font-bold text-gray-700" />
            <x-text-input id="update_password_current_password" name="current_password" type="password" class="mt-1 block w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold focus:border-primary-container focus:ring-primary-container" autocomplete="current-password" />
            <x-input-error :messages="$errors->updatePassword->get('current_password')" class="mt-1 text-xs" />
        </div>

        <div>
            <x-input-label for="update_password_password" :value="__('Mật khẩu mới')" class="font-bold text-gray-700" />
            <x-text-input id="update_password_password" name="password" type="password" class="mt-1 block w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold focus:border-primary-container focus:ring-primary-container" autocomplete="new-password" />
            <x-input-error :messages="$errors->updatePassword->get('password')" class="mt-1 text-xs" />
        </div>

        <div>
            <x-input-label for="update_password_password_confirmation" :value="__('Xác nhận mật khẩu mới')" class="font-bold text-gray-700" />
            <x-text-input id="update_password_password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold focus:border-primary-container focus:ring-primary-container" autocomplete="new-password" />
            <x-input-error :messages="$errors->updatePassword->get('password_confirmation')" class="mt-1 text-xs" />
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition">
                Cập nhật mật khẩu
            </button>

            @if (session('status') === 'password-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2500)"
                    class="text-xs text-emerald-600 font-bold flex items-center gap-1"
                >
                    <span class="material-symbols-outlined text-sm">check_circle</span>
                    <span>Đã đổi mật khẩu thành công!</span>
                </p>
            @endif
        </div>
    </form>
</section>
