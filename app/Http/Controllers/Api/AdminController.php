<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Access;
use App\Support\Audit;
use App\Support\UiText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\Facades\DataTables;

class AdminController extends Controller
{
    public function users()
    {
        Access::allow('users.manage');

        return DataTables::eloquent(User::query()->select('id', 'name', 'email', 'active', 'all_branches')->with('roles:id,name', 'branches:id,name'))->escapeColumns([])->toJson();
    }

    public function userSave(Request $r, ?User $user = null)
    {
        Access::allow('users.manage');
        $user ??= new User;
        $d = $r->validate(['name' => 'required|string|max:190', 'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($user->id)], 'password' => [$user->exists ? 'nullable' : 'required', 'string', 'min:12', 'max:128'], 'active' => 'required|boolean', 'all_branches' => 'required|boolean', 'roles' => 'required|array|min:1', 'roles.*' => 'required|exists:roles,name', 'branch_ids' => 'present|array', 'branch_ids.*' => 'integer|distinct|exists:branches,id']);
        abort_unless($d['all_branches'] || count($d['branch_ids']) > 0, 422, 'اختر فرعًا واحدًا على الأقل.');

        return DB::transaction(function () use ($user, $d) {
            $adminRole = Role::where('name', 'admin')->lockForUpdate()->firstOrFail();
            if ($user->exists) {
                $user = User::lockForUpdate()->findOrFail($user->id);
                if ($user->hasRole('admin') && (! $d['active'] || ! in_array('admin', $d['roles']) || ! $d['all_branches'])) {
                    abort_if(User::role('admin')->where('active', true)->where('id', '!=', $user->id)->count() === 0, 422, 'لا يمكن تعطيل آخر مدير نظام أو إزالة صلاحياته.');
                }
            }
            if ($user->id === auth()->id()) {
                abort_unless($d['active'] && in_array('admin', $d['roles']) && $d['all_branches'], 422, 'لا يمكنك إزالة صلاحيات إدارة حسابك الحالي.');
            }
            $values = collect($d)->only('name', 'email', 'active', 'all_branches')->all();
            if (! empty($d['password'])) {
                $values['password'] = $d['password'];
            }
            $user->fill($values)->save();
            $user->syncRoles($d['roles']);
            $user->branches()->sync($d['branch_ids']);
            Audit::record('users.saved', $user, ['roles' => $d['roles'], 'active' => $d['active'], 'all_branches' => $d['all_branches'], 'branch_ids' => $d['branch_ids']]);

            return $user->load('roles', 'branches');
        });
    }

    public function roles()
    {
        abort_unless(auth()->user()->can('roles.manage') || auth()->user()->can('users.manage'), 403);

        return [
            'roles' => Role::with('permissions:id,name')->get(),
            'permissions' => config('rotana.permissions'),
            'permission_groups' => UiText::permissionGroups(),
            'permission_catalog' => UiText::permissionCatalog(),
            'role_labels' => UiText::roleLabels(),
        ];
    }

    public function roleSave(Request $r, ?Role $role = null)
    {
        Access::allow('roles.manage');
        $role ??= new Role;
        $d = $r->validate(['name' => ['required', 'string', 'max:100', Rule::unique('roles')->ignore($role->id)], 'permissions' => 'present|array', 'permissions.*' => ['string', Rule::in(config('rotana.permissions'))]]);
        abort_if($role->exists && $role->name === 'admin', 422, 'دور مدير النظام محمي. أنشئ دورًا مخصصًا بدلًا منه.');

        return DB::transaction(function () use ($role, $d) {
            $role->fill(['name' => $d['name'], 'guard_name' => 'web'])->save();
            $role->syncPermissions($d['permissions']);
            Audit::record('roles.saved', $role, ['permissions' => $d['permissions']]);

            return $role->load('permissions');
        });
    }

    public function activity()
    {
        Access::allow('activity.view');
        $q = Activity::with('causer:id,name', 'subject')->latest('id');
        if (! auth()->user()->all_branches) {
            $q->whereIn('properties->branch_id', auth()->user()->branches()->pluck('branches.id'));
        }

        return DataTables::eloquent($q)
            ->addColumn('event_label', fn (Activity $activity) => UiText::activityEventLabel($activity->event))
            ->addColumn('subject_label', fn (Activity $activity) => UiText::subjectLabel($activity->subject_type))
            ->addColumn('subject_reference', fn (Activity $activity) => UiText::subjectReference($activity))
            ->addColumn('details', fn (Activity $activity) => UiText::activityDetails($activity))
            ->escapeColumns([])
            ->toJson();
    }
}
