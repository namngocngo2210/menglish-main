<section>
    <header class="space-y-1">
        <h2 class="text-base font-bold text-gray-900">
            Thông tin tài khoản
        </h2>

        <p class="text-xs text-gray-500">
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
            <x-input-label for="name" :value="__('Họ và tên')" class="font-bold text-gray-700" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold focus:border-primary-container focus:ring-primary-container" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-1 text-xs" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email đăng nhập')" class="font-bold text-gray-700" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full text-xs rounded-xl border border-gray-200 p-2.5 font-semibold focus:border-primary-container focus:ring-primary-container" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-1 text-xs" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-xs mt-2 text-gray-800">
                        {{ __('Địa chỉ email của bạn chưa được xác thực.') }}

                        @if (Route::has('verification.send'))
                            <button form="send-verification" class="underline text-xs text-primary hover:text-primary-hover rounded-md focus:outline-none">
                                {{ __('Bấm vào đây để gửi lại email xác thực.') }}
                            </button>
                        @endif
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-xs text-emerald-600">
                            {{ __('Đã gửi liên kết xác thực mới đến email của bạn.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition">
                Lưu thay đổi
            </button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2500)"
                    class="text-xs text-emerald-600 font-bold flex items-center gap-1"
                >
                    <span class="material-symbols-outlined text-sm">check_circle</span>
                    <span>Đã cập nhật thông tin thành công!</span>
                </p>
            @endif
        </div>
    </form>
</section>
