<section>
    <header class="space-y-1">
        <h2 class="text-base font-bold text-on-surface">
            Thông tin tài khoản
        </h2>

        <p class="text-xs text-on-surface-variant">
            Cập nhật tên hiển thị và địa chỉ email đăng nhập của bạn
        </p>
    </header>

    @if (Route::has('verification.send'))
        <form id="send-verification" method="post" action="{{ route('verification.send') }}">
            @csrf
        </form>
    @endif

    <form method="post" action="{{ route('profile.update') }}" class="mt-5 space-y-4 text-xs">
        @csrf
        @method('patch')

        <div>
            <x-ui.input id="name" name="name" type="text" :label="__('Họ và tên')" :value="$user->name" required autofocus autocomplete="name" />
        </div>

        <div>
            <x-ui.input id="email" name="email" type="email" :label="__('Email đăng nhập')" :value="$user->email" required autocomplete="username" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-xs mt-2 text-on-surface">
                        {{ __('Địa chỉ email của bạn chưa được xác thực.') }}

                        @if (Route::has('verification.send'))
                            <button form="send-verification" class="underline text-xs text-primary hover:text-primary-hover rounded-md focus:outline-none">
                                {{ __('Bấm vào đây để gửi lại email xác thực.') }}
                            </button>
                        @endif
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-xs text-tertiary">
                            {{ __('Đã gửi liên kết xác thực mới đến email của bạn.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex items-center gap-3 pt-2">
            <x-ui.button type="submit">
                Lưu thay đổi
            </x-ui.button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2500)"
                    class="text-xs text-tertiary font-bold flex items-center gap-1"
                >
                    <span class="material-symbols-outlined text-sm">check_circle</span>
                    <span>Đã cập nhật thông tin thành công!</span>
                </p>
            @endif
        </div>
    </form>
</section>
