<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Item;
use App\Models\MaintenanceCard;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

final class UiText
{
    public static function roleLabels(): array
    {
        return config('rotana.role_labels', []);
    }

    public static function roleLabel(?string $role): string
    {
        return self::roleLabels()[$role] ?? ($role ?: 'دور غير معروف');
    }

    public static function permissionGroups(): array
    {
        return config('rotana.permission_groups', []);
    }

    public static function permissionCatalog(): array
    {
        return config('rotana.permission_catalog', []);
    }

    public static function permissionMeta(string $permission): array
    {
        return self::permissionCatalog()[$permission]
            ?? ['group' => 'misc', 'label' => 'صلاحية غير مصنفة', 'description' => 'صلاحية مسجلة في النظام ولم تُصنف بعد.'];
    }

    public static function categoryLabel(?string $value): string
    {
        return config('rotana.categories')[$value] ?? 'تصنيف غير معروف';
    }

    public static function statusLabel(?string $value): string
    {
        if (! $value) {
            return 'غير محدد';
        }

        return OrderStatus::tryFrom($value)?->label() ?? 'حالة غير معروفة';
    }

    public static function priorityLabel(?string $value): string
    {
        return [
            'normal' => 'عادي',
            'urgent' => 'عاجل',
            'critical' => 'عاجل جدًا',
        ][$value] ?? 'غير محدد';
    }

    public static function booleanLabel(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ? 'نعم' : 'لا';
    }

    public static function movementTypeLabel(?string $value): string
    {
        return [
            'issue' => 'صرف على سيارة',
            'transfer' => 'تحويل بين المخازن',
            'return' => 'مرتجع إلى المخزن',
            'adjust' => 'تسوية جرد',
            'receipt' => 'استلام مخزني',
            'receipt_correction' => 'تصحيح استلام مخزني',
        ][$value] ?? 'حركة غير معروفة';
    }

    public static function cardStatusLabel(?string $value): string
    {
        return [
            'pending' => 'مفتوح',
            'waiting_parts' => 'بانتظار القطع',
            'in_progress' => 'قيد الصيانة',
            'completed' => 'مكتمل',
            'closed' => 'مغلق',
        ][$value] ?? 'حالة غير معروفة';
    }

    public static function activityEventLabel(?string $event): string
    {
        return [
            'auth.login' => 'تسجيل دخول',
            'auth.logout' => 'تسجيل خروج',
            'users.saved' => 'حفظ بيانات مستخدم',
            'roles.saved' => 'حفظ دور وصلاحياته',
            'orders.saved' => 'حفظ طلب شراء',
            'orders.submitted' => 'إرسال الطلب للمراجعة',
            'orders.review' => 'اعتماد محاسبي',
            'orders.approve_manager' => 'اعتماد المدير',
            'orders.approve_supervisor' => 'اعتماد المشرف',
            'orders.return' => 'إعادة الطلب للتعديل',
            'orders.reject' => 'رفض الطلب',
            'orders.matched' => 'اعتماد المطابقة',
            'orders.rematch_required' => 'إعادة فتح المطابقة',
            'orders.closed' => 'إغلاق الطلب',
            'receipts.created' => 'تسجيل استلام',
            'receipts.corrected' => 'تصحيح استلام',
            'invoices.saved' => 'حفظ فاتورة مورد',
            'payments.created' => 'تسجيل دفعة',
            'inventory.issue' => 'صرف مخزني',
            'inventory.transfer' => 'تحويل مخزني',
            'inventory.return' => 'مرتجع مخزني',
            'inventory.adjust' => 'تسوية جرد',
            'cards.created' => 'إنشاء كارت صيانة',
            'cards.status_changed' => 'تحديث مرحلة كارت الصيانة',
            'masters.regions.saved' => 'حفظ منطقة',
            'masters.branches.saved' => 'حفظ فرع',
            'masters.cost-centers.saved' => 'حفظ مركز تكلفة',
            'masters.warehouses.saved' => 'حفظ مخزن',
            'masters.suppliers.saved' => 'حفظ مورد',
            'masters.vehicles.saved' => 'حفظ سيارة',
            'masters.items.saved' => 'حفظ صنف',
        ][$event] ?? 'نشاط مسجل';
    }

    public static function subjectLabel(?string $subjectType): string
    {
        return [
            PurchaseOrder::class => 'طلب شراء',
            User::class => 'مستخدم',
            Vehicle::class => 'سيارة',
            Supplier::class => 'مورد',
            Item::class => 'صنف',
            Branch::class => 'فرع',
            Warehouse::class => 'مخزن',
            CostCenter::class => 'مركز تكلفة',
            MaintenanceCard::class => 'كارت صيانة',
            StockMovement::class => 'حركة مخزنية',
            'Spatie\\Permission\\Models\\Role' => 'دور',
        ][$subjectType] ?? 'سجل';
    }

    public static function fieldLabel(string $field): string
    {
        return [
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'active' => 'الحالة',
            'all_branches' => 'نطاق الفروع',
            'roles' => 'الأدوار',
            'branch_ids' => 'الفروع',
            'permissions' => 'الصلاحيات',
            'branch_id' => 'الفرع',
            'cost_center_id' => 'مركز التكلفة',
            'supplier_id' => 'المورد',
            'vehicle_id' => 'السيارة',
            'warehouse_id' => 'المخزن',
            'destination_warehouse_id' => 'المخزن المستلم',
            'source_movement_id' => 'إذن الصرف الأصلي',
            'item_id' => 'الصنف',
            'media_id' => 'المرفق',
            'reference' => 'مرجع التحويل',
            'amount' => 'المبلغ',
            'number' => 'الرقم المرجعي',
            'date' => 'التاريخ',
            'category' => 'نوع الطلب',
            'priority' => 'الأولوية',
            'quote_number' => 'رقم عرض السعر',
            'tax_percent' => 'نسبة الضريبة',
            'notes' => 'الملاحظات',
            'status' => 'الحالة',
            'type' => 'النوع',
            'odometer' => 'قراءة العداد',
            'sku' => 'كود الصنف',
            'unit' => 'الوحدة',
            'track_stock' => 'تتبع المخزون',
            'unit_cost' => 'تكلفة الوحدة',
            'minimum' => 'حد إعادة الطلب',
            'plate' => 'لوحة السيارة',
            'vin' => 'رقم الهيكل',
            'model' => 'الموديل',
            'year' => 'سنة الصنع',
            'color' => 'اللون',
            'region_id' => 'المنطقة',
            'code' => 'الكود',
            'phone' => 'رقم الهاتف',
            'tax_number' => 'الرقم الضريبي',
            'iban' => 'رقم الآيبان',
            'address' => 'العنوان',
            'from' => 'الحالة السابقة',
            'to' => 'الحالة الحالية',
            'reason' => 'السبب',
            'total_minor' => 'إجمالي الطلب',
            'quantity_milli' => 'الكمية',
            'lines' => 'البنود',
            'quantities' => 'الكميات',
        ][$field] ?? 'بيان';
    }

    public static function frontend(): array
    {
        return [
            'role_labels' => self::roleLabels(),
            'permission_groups' => self::permissionGroups(),
            'permission_catalog' => self::permissionCatalog(),
            'priority_labels' => [
                'normal' => 'عادي',
                'urgent' => 'عاجل',
                'critical' => 'عاجل جدًا',
            ],
            'movement_type_labels' => [
                'issue' => 'صرف على سيارة',
                'transfer' => 'تحويل بين المخازن',
                'return' => 'مرتجع إلى المخزن',
                'adjust' => 'تسوية جرد',
                'receipt' => 'استلام مخزني',
                'receipt_correction' => 'تصحيح استلام مخزني',
            ],
            'card_status_labels' => [
                'pending' => 'مفتوح',
                'waiting_parts' => 'بانتظار القطع',
                'in_progress' => 'قيد الصيانة',
                'completed' => 'مكتمل',
                'closed' => 'مغلق',
            ],
        ];
    }

    public static function activityDetails(Activity $activity): array
    {
        $properties = $activity->properties instanceof Collection ? $activity->properties->toArray() : (array) $activity->properties;
        $details = [];
        $changes = [];

        foreach (['branch_id', 'from', 'to', 'reason', 'status', 'type', 'total_minor', 'number', 'reference'] as $field) {
            if (Arr::has($properties, $field) && filled($properties[$field])) {
                $details[] = [
                    'label' => self::fieldLabel($field),
                    'value' => self::formatFieldValue($field, $properties[$field]),
                ];
            }
        }

        if (isset($properties['roles'])) {
            $details[] = [
                'label' => self::fieldLabel('roles'),
                'value' => collect($properties['roles'])->map(fn ($role) => self::roleLabel((string) $role))->implode('، '),
            ];
        }

        if (isset($properties['permissions'])) {
            $details[] = [
                'label' => self::fieldLabel('permissions'),
                'value' => collect($properties['permissions'])->map(fn ($permission) => self::permissionMeta((string) $permission)['label'])->implode('، '),
            ];
        }

        if (isset($properties['branch_ids'])) {
            $details[] = [
                'label' => self::fieldLabel('branch_ids'),
                'value' => collect($properties['branch_ids'])->map(fn ($id) => self::lookupName('branch_id', $id))->implode('، '),
            ];
        }

        if (isset($properties['old']) || isset($properties['new'])) {
            foreach (collect(array_keys((array) ($properties['old'] ?? [])))->merge(array_keys((array) ($properties['new'] ?? [])))->unique() as $field) {
                $changes[] = [
                    'field' => self::fieldLabel((string) $field),
                    'before' => self::formatFieldValue((string) $field, data_get($properties, "old.$field")),
                    'after' => self::formatFieldValue((string) $field, data_get($properties, "new.$field")),
                ];
            }
        }

        if (isset($properties['lines']) && is_array($properties['lines'])) {
            $details[] = [
                'label' => 'البنود',
                'value' => collect($properties['lines'])->map(function ($line) {
                    $item = self::lookupName('item_id', $line['item_id'] ?? null);
                    $qty = isset($line['quantity_milli']) ? number_format(((int) $line['quantity_milli']) / 1000, 3, '.', '') : null;

                    return trim($item.($qty ? ' × '.$qty : ''));
                })->filter()->implode('، '),
            ];
        }

        if (isset($properties['quantities']) && is_array($properties['quantities'])) {
            $details[] = [
                'label' => 'الكميات',
                'value' => collect($properties['quantities'])->map(function ($line) {
                    $lineId = $line['order_line_id'] ?? null;
                    $quantity = isset($line['quantity_milli']) ? number_format(((int) $line['quantity_milli']) / 1000, 3, '.', '') : null;

                    return $lineId ? 'بند رقم '.$lineId.' بكمية '.$quantity : null;
                })->filter()->implode('، '),
            ];
        }

        return ['summary' => self::activityEventLabel($activity->event), 'details' => $details, 'changes' => $changes];
    }

    public static function subjectReference(Activity $activity): string
    {
        $subject = $activity->subject;
        if (! $subject) {
            return $activity->subject_id ? 'رقم '.$activity->subject_id : '—';
        }

        foreach (['number', 'name', 'plate', 'email', 'code', 'sku'] as $key) {
            if (filled($subject->{$key} ?? null)) {
                return (string) $subject->{$key};
            }
        }

        return $activity->subject_id ? 'رقم '.$activity->subject_id : '—';
    }

    private static function formatFieldValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (in_array($field, ['active', 'all_branches', 'track_stock'], true)) {
            return self::booleanLabel($value);
        }

        if ($field === 'category') {
            return self::categoryLabel((string) $value);
        }

        if ($field === 'priority') {
            return self::priorityLabel((string) $value);
        }

        if (in_array($field, ['status', 'from', 'to'], true)) {
            return self::statusLabel((string) $value);
        }

        if ($field === 'type') {
            return self::movementTypeLabel((string) $value);
        }

        if (Str::endsWith($field, '_id')) {
            return self::lookupName($field, $value);
        }

        if ($field === 'roles' && is_array($value)) {
            return collect($value)->map(fn ($role) => self::roleLabel((string) $role))->implode('، ');
        }

        if ($field === 'permissions' && is_array($value)) {
            return collect($value)->map(fn ($permission) => self::permissionMeta((string) $permission)['label'])->implode('، ');
        }

        if (is_numeric($value) && in_array($field, ['total_minor', 'amount_minor', 'unit_cost_minor', 'unit_price_minor'], true)) {
            return number_format(((int) $value) / 100, 2).' ريال';
        }

        if (is_numeric($value) && in_array($field, ['quantity_milli', 'minimum_milli'], true)) {
            return number_format(((int) $value) / 1000, 3);
        }

        return (string) $value;
    }

    private static function lookupName(string $field, mixed $id): string
    {
        if (! $id) {
            return '—';
        }

        return match ($field) {
            'branch_id' => Branch::query()->whereKey($id)->value('name') ?? 'فرع رقم '.$id,
            'cost_center_id' => CostCenter::query()->whereKey($id)->value('name') ?? 'مركز تكلفة رقم '.$id,
            'supplier_id' => Supplier::query()->whereKey($id)->value('name') ?? 'مورد رقم '.$id,
            'vehicle_id' => Vehicle::query()->whereKey($id)->value('plate') ?? 'سيارة رقم '.$id,
            'warehouse_id', 'destination_warehouse_id' => Warehouse::query()->whereKey($id)->value('name') ?? 'مخزن رقم '.$id,
            'item_id' => Item::query()->whereKey($id)->value('name') ?? 'صنف رقم '.$id,
            'media_id' => 'مرفق رقم '.$id,
            'source_movement_id' => StockMovement::query()->whereKey($id)->value('number') ?? 'حركة رقم '.$id,
            'region_id' => \App\Models\Region::query()->whereKey($id)->value('name') ?? 'منطقة رقم '.$id,
            default => 'رقم '.$id,
        };
    }
}
