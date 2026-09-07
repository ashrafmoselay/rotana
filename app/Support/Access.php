<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class Access
{
    public static function branch(int $id, ?User $user = null): void
    {
        abort_unless(($user ?? auth()->user())->mayAccessBranch($id), 403, 'هذا الفرع خارج نطاق صلاحياتك.');
    }

    public static function scope(Builder $query, string $column = 'branch_id', ?User $user = null): Builder
    {
        $user ??= auth()->user();

        return $user->all_branches ? $query : $query->whereIn($column, $user->branches()->select('branches.id'));
    }

    public static function allow(string $permission): void
    {
        abort_unless(auth()->user()->can($permission), 403, 'ليست لديك صلاحية تنفيذ هذا الإجراء.');
    }
}
