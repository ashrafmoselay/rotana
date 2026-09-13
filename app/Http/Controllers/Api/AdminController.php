<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Access;
use App\Support\Audit;
use App\Support\UiText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\FileRemover\FileRemoverFactory;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Yajra\DataTables\Facades\DataTables;

class AdminController extends Controller
{
    private const RESET_TABLES = ['payments', 'invoice_lines', 'supplier_invoices', 'receipt_lines', 'receipts',
        'stock_movement_lines', 'stock_movements', 'approval_events', 'order_lines', 'purchase_orders',
        'maintenance_cards', 'stock_balances', 'media', 'vehicles', 'items', 'suppliers', 'warehouses',
        'branch_user', 'branches', 'cost_centers', 'regions', 'activity_log', 'jobs', 'job_batches', 'failed_jobs'];

    private function resetSnapshot(): array
    {
        $snapshot = [];
        foreach (self::RESET_TABLES as $table) {
            $snapshot[$table] = DB::table($table)->count();
        }
        $snapshot['users'] = User::whereKeyNot(auth()->id())->count();

        return $snapshot;
    }

    private function databaseConnection(): array
    {
        $connection = config('database.default');
        $settings = config('database.connections.'.$connection);

        abort_unless(in_array($settings['driver'] ?? null, ['mysql', 'mariadb'], true), 422, 'النسخ الاحتياطي والاسترجاع متاحان لقواعد بيانات MySQL فقط.');

        return $settings;
    }

    private function mysqlCommand(string $binary, array $settings): array
    {
        $command = [$binary, '--user='.$settings['username'], '--default-character-set='.($settings['charset'] ?? 'utf8mb4')];

        if (! empty($settings['unix_socket'])) {
            $command[] = '--socket='.$settings['unix_socket'];
        } else {
            $command[] = '--host='.($settings['host'] ?? '127.0.0.1');
            $command[] = '--port='.($settings['port'] ?? 3306);
        }

        return $command;
    }

    private function maintenanceLock()
    {
        $lock = fopen(storage_path('app/maintenance.lock'), 'c');
        abort_unless($lock, 500, 'تعذر بدء العملية.');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            abort(409, 'توجد عملية صيانة قيد التنفيذ.');
        }

        return $lock;
    }

    public function databaseBackup()
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);
        $settings = $this->databaseConnection();
        $lock = $this->maintenanceLock();

        return response()->streamDownload(function () use ($settings, $lock) {
            try {
                $command = array_merge($this->mysqlCommand('mysqldump', $settings), [
                    '--single-transaction', '--routines', '--events', '--no-tablespaces', $settings['database'],
                ]);
                $process = new Process($command, null, ['MYSQL_PWD' => $settings['password'] ?? ''], null, 900);
                $process->start();
                foreach ($process as $type => $output) {
                    if ($type === Process::OUT) {
                        echo $output;
                    }
                }
                if (! $process->isSuccessful()) {
                    report(new \RuntimeException('Database backup failed: '.$process->getErrorOutput()));
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }, 'rotana-backup-'.now()->format('Y-m-d-His').'.sql', ['Content-Type' => 'application/sql; charset=UTF-8']);
    }

    public function restoreDatabase(Request $request)
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);
        $settings = $this->databaseConnection();
        $data = $request->validate([
            'password' => 'required|current_password',
            'confirmation' => ['required', Rule::in(['استرجاع النسخة الاحتياطية'])],
            'file' => 'required|file|extensions:sql|max:204800',
        ]);
        /** @var UploadedFile $file */
        $file = $data['file'];
        $lock = $this->maintenanceLock();

        try {
            $input = fopen($file->getRealPath(), 'rb');
            abort_unless($input, 422, 'تعذر قراءة ملف النسخة الاحتياطية.');
            try {
                $command = array_merge($this->mysqlCommand('mysql', $settings), ['--binary-mode=1', '--database='.$settings['database']]);
                $process = new Process($command, null, ['MYSQL_PWD' => $settings['password'] ?? ''], $input, 900);
                $process->run();
                if (! $process->isSuccessful()) {
                    report(new \RuntimeException('Database restore failed: '.$process->getErrorOutput()));
                    abort(422, 'تعذر استرجاع النسخة الاحتياطية. لم يكتمل الاسترجاع؛ راجع المسؤول التقني.');
                }
            } finally {
                fclose($input);
            }

            DB::purge();

            return response()->json(['message' => 'تم استرجاع قاعدة البيانات. سيتم إعادة تحميل النظام الآن.']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function resetPreview()
    {
        abort_unless(auth()->user()->hasRole('admin') && auth()->user()->all_branches, 403);
        $counts = $this->resetSnapshot();
        $token = Str::random(64);
        Cache::put('data-reset:'.$token, ['user_id' => auth()->id(), 'counts' => $counts], now()->addMinutes(5));

        return response()->json(['token' => $token, 'counts' => $counts])->header('Cache-Control', 'no-store');
    }

    public function resetData(Request $request)
    {
        abort_unless(auth()->user()->hasRole('admin') && auth()->user()->all_branches, 403);
        $data = $request->validate(['password' => 'required|current_password', 'confirmation' => ['required', Rule::in(['مسح كل بيانات التجربة'])], 'token' => 'required|string|size:64']);
        $lock = fopen(storage_path('app/maintenance.lock'), 'c');
        abort_unless($lock, 500, 'تعذر بدء العملية.');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            abort(409, 'توجد عملية صيانة قيد التنفيذ.');
        }
        try {
            $preview = Cache::pull('data-reset:'.$data['token']);
            abort_unless($preview && $preview['user_id'] === auth()->id(), 422, 'انتهى التأكيد أو استُخدم بالفعل. افتح نافذة التأكيد مجددًا.');
            $media = DB::transaction(function () use ($preview) {
                // Lock existing parent rows before removing their dependent records.
                foreach (['users', 'regions', 'branches', 'cost_centers', 'warehouses', 'suppliers', 'vehicles', 'items', 'maintenance_cards', 'purchase_orders', 'stock_movements', 'media'] as $table) {
                    DB::table($table)->orderBy('id')->lockForUpdate()->pluck('id');
                }
                abort_unless($preview['counts'] === $this->resetSnapshot(), 409, 'تغيرت البيانات منذ عرض التأكيد. راجع الأعداد وأكد مجددًا.');
                $media = Media::all();
                // Break only the internal movement reference; foreign keys stay enabled.
                DB::table('stock_movements')->update(['source_movement_id' => null]);
                foreach (self::RESET_TABLES as $table) {
                    DB::table($table)->delete();
                }
                foreach (['model_has_roles', 'model_has_permissions'] as $pivot) {
                    DB::table(config('permission.table_names.'.$pivot))
                        ->where(fn ($q) => $q->where('model_type', '!=', auth()->user()->getMorphClass())->orWhere('model_id', '!=', auth()->id()))->delete();
                }
                DB::table('sessions')->where('user_id', '!=', auth()->id())->orWhereNull('user_id')->delete();
                DB::table('password_reset_tokens')->delete();
                User::whereKeyNot(auth()->id())->delete();
                Audit::record('system.trial_data_reset', null, ['deleted_counts' => $preview['counts']]);

                return $media;
            });
            // Physical files are removed only after the database transaction succeeds.
            $failed = 0;
            foreach ($media as $file) {
                try {
                    FileRemoverFactory::create($file)->removeAllFiles($file);
                    if (Storage::disk($file->disk)->exists($file->getPathRelativeToRoot())) {
                        throw new \RuntimeException('Attachment cleanup failed for media '.$file->id);
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $failed++;
                }
            }

            return response()->json(['message' => $failed ? 'تم مسح البيانات، لكن تعذر حذف بعض ملفات المرفقات من التخزين؛ يلزم مراجعة المسؤول التقني.' : 'تم مسح بيانات التجربة مع الاحتفاظ بحسابك والصلاحيات.', 'file_cleanup_failures' => $failed]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function maintenance()
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);

        // A filesystem lock survives cache:clear and prevents overlapping runs.
        $lock = fopen(storage_path('app/maintenance.lock'), 'c');
        abort_unless($lock, 500, 'تعذر بدء صيانة النظام.');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            abort(409, 'توجد عملية صيانة قيد التنفيذ.');
        }

        $results = [];
        try {
            foreach ([
                'migrate' => ['تحديث قاعدة البيانات', ['--force' => true]],
                'cache:clear' => ['مسح كاش التطبيق', []],
                'config:clear' => ['مسح كاش الإعدادات', []],
                'route:clear' => ['مسح كاش المسارات', []],
                'view:clear' => ['مسح كاش القوالب', []],
            ] as $command => [$label, $options]) {
                try {
                    $success = Artisan::call($command, $options) === 0;
                } catch (\Throwable $e) {
                    report($e);
                    $success = false;
                }
                $results[] = ['label' => $label, 'success' => $success];
                if (! $success) {
                    break;
                }
            }

            if (! in_array(false, array_column($results, 'success'), true)) {
                $path = public_path('storage');
                try {
                    if (! file_exists($path) && ! is_link($path)) {
                        File::link(storage_path('app/public'), $path);
                    }
                    $success = is_link($path) && is_dir($path) && realpath($path) === realpath(storage_path('app/public'));
                } catch (\Throwable $e) {
                    report($e);
                    $success = false;
                }
                $results[] = ['label' => 'رابط storage في public', 'success' => $success];
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $success = ! in_array(false, array_column($results, 'success'), true);

        return response()->json([
            'message' => $success ? 'تمت صيانة النظام بنجاح.' : 'توقفت الصيانة عند خطوة غير ناجحة. راجع النتائج؛ الخطوات السابقة قد تم تنفيذها.',
            'results' => $results,
        ], $success ? 200 : 500);
    }

    public function users(Request $r)
    {
        Access::allow('users.manage');
        $this->clampDataTableLength($r);

        $q = User::query()->select('id', 'name', 'email', 'active', 'all_branches')->with('roles:id,name', 'branches:id,name');
        if (! auth()->user()->all_branches) {
            $q->where('all_branches', false)
                ->whereHas('branches', fn ($q) => $q->whereIn('branches.id', auth()->user()->branches()->select('branches.id')));
        }

        return DataTables::eloquent($q)->escapeColumns([])->toJson();
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

    public function activity(Request $r)
    {
        Access::allow('activity.view');
        $this->clampDataTableLength($r);
        $q = Activity::with('causer:id,name', 'subject')->latest('id');
        if (! auth()->user()->all_branches) {
            $q->whereIn('properties->branch_id', auth()->user()->branches()->pluck('branches.id'));
        }

        return DataTables::eloquent($q)
            ->addColumn('event_label', fn (Activity $activity) => UiText::activityEventLabel($activity->event))
            ->addColumn('subject_label', fn (Activity $activity) => UiText::subjectLabel($activity->subject_type))
            ->addColumn('subject_reference', fn (Activity $activity) => UiText::subjectReference($activity))
            ->addColumn('details', fn (Activity $activity) => UiText::activityDetails($activity))
            ->removeColumn('properties')
            ->removeColumn('subject')
            ->escapeColumns([])
            ->toJson();
    }

    private function clampDataTableLength(Request $r): void
    {
        if ($r->has('length')) {
            $r->merge(['length' => min(max((int) $r->input('length'), 1), 100)]);
        }
    }
}
