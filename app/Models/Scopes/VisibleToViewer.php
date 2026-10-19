<?php

namespace App\Models\Scopes;

use App\Support\DataScopeGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/** Global scope của App\Models\Concerns\ScopedToViewer: lọc theo người đang đăng nhập khi DataScopeGuard đang bật. */
class VisibleToViewer implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! DataScopeGuard::current()->active()) {
            return;
        }

        $user = Auth::user();
        if (! $user) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $model::constrainToViewer($builder, $user);
    }
}
