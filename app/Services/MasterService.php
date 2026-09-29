<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Item;
use App\Models\OrderLine;
use App\Models\Region;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Support\Access;
use App\Support\Amounts;
use App\Support\Audit;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MasterService
{
    public const MODELS = ['regions' => Region::class, 'branches' => Branch::class, 'cost-centers' => CostCenter::class, 'warehouses' => Warehouse::class, 'suppliers' => Supplier::class, 'vehicles' => Vehicle::class, 'items' => Item::class];

    private const AUTO_CODE_FIELDS = [
        'branches' => ['field' => 'code', 'prefix' => 'BR'],
        'cost-centers' => ['field' => 'code', 'prefix' => 'CC'],
        'warehouses' => ['field' => 'code', 'prefix' => 'WH'],
        'suppliers' => ['field' => 'code', 'prefix' => 'SUP'],
    ];

    public static function permission(string $kind): string
    {
        return match ($kind) {
            'suppliers' => 'suppliers.manage','vehicles' => 'vehicles.manage','items' => 'items.manage',default => 'masters.manage'
        };
    }

    public static function plate(string $plate): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', strtr(trim($plate), array_combine(preg_split('//u', '٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), range(0, 9)))));
    }

    public function save(string $kind, array $input, ?int $id = null)
    {
        Access::allow(self::permission($kind));
        $model = self::MODELS[$kind] ?? abort(404);
        $record = $id ? $model::lockForUpdate()->findOrFail($id) : new $model;
        if ($record->exists && in_array($kind, ['vehicles', 'warehouses'])) {
            Access::branch($record->branch_id);
        }
        if ($record->exists && $kind === 'branches') {
            Access::branch($record->id);
        }
        $rules = ['name' => 'required|string|max:190', 'active' => 'sometimes|boolean'];
        if ($kind === 'regions') {
            $rules['name'] = ['required', 'string', 'max:190', Rule::unique('regions', 'name')->ignore($id)];
        }
        if ($kind === 'branches') {
            $rules['region_id'] = 'required|integer|exists:regions,id';
        }
        if ($kind === 'warehouses') {
            $rules['branch_id'] = 'required|integer|exists:branches,id';
        }
        if ($kind === 'suppliers') {
            $rules += ['phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:190', 'tax_number' => 'nullable|string|max:50', 'iban' => 'nullable|string|max:50', 'address' => 'nullable|string|max:1000'];
        }
        if ($kind === 'items') {
            $rules += ['sku' => ['required', 'string', 'max:60', Rule::unique('items', 'sku')->ignore($id)], 'unit' => 'required|string|max:30', 'track_stock' => 'required|boolean', 'unit_cost' => 'required|numeric|min:0|max:10000000', 'minimum' => 'required|integer|min:0|max:1000000'];
        }
        if ($kind === 'vehicles') {
            $input['plate_key'] = self::plate($input['plate'] ?? '');
            $rules = ['plate' => 'required|string|max:50', 'plate_key' => ['required', Rule::unique('vehicles')->ignore($id)], 'vin' => ['nullable', 'string', 'max:64', Rule::unique('vehicles')->ignore($id)], 'model' => 'required|string|max:150', 'year' => 'required|integer|min:1900|max:'.(now()->year + 2), 'color' => 'required|string|max:50', 'odometer' => 'required|integer|min:0|max:999999999', 'branch_id' => 'required|integer|exists:branches,id', 'cost_center_id' => 'required|integer|exists:cost_centers,id', 'active' => 'sometimes|boolean'];
        }
        $data = Validator::make($input, $rules)->validate();
        if (isset($data['branch_id'])) {
            Access::branch((int) $data['branch_id']);
        }
        if ($kind === 'branches' && ! auth()->user()->all_branches) {
            abort(403, 'إدارة الفروع تتطلب نطاق جميع الفروع.');
        }
        if ($kind === 'items') {
            $data['unit_cost_minor'] = Amounts::scaled($data['unit_cost']);
            $data['minimum_milli'] = Amounts::scaled($data['minimum'], 3);
            unset($data['unit_cost'],$data['minimum']);
            if ($record->exists && $record->track_stock !== (bool) $data['track_stock'] && ($record->balances()->exists() || OrderLine::where('item_id', $record->id)->exists())) {
                abort(422, 'لا يمكن تغيير تتبع المخزون لصنف له معاملات.');
            }
        }
        if ($kind === 'vehicles' && $record->exists && $data['odometer'] < $record->odometer) {
            abort(422, 'لا يمكن خفض عداد السيارة.');
        }
        $old = $record->only(array_keys($data));
        $record->fill($data);
        if (! $record->exists && isset(self::AUTO_CODE_FIELDS[$kind])) {
            $record->setAttribute(self::AUTO_CODE_FIELDS[$kind]['field'], 'TMP-'.Str::uuid());
        }
        $record->save();
        if (isset(self::AUTO_CODE_FIELDS[$kind])) {
            $code = $this->automaticCode($kind, $record);
            if ($record->getAttribute(self::AUTO_CODE_FIELDS[$kind]['field']) !== $code) {
                $record->setAttribute(self::AUTO_CODE_FIELDS[$kind]['field'], $code);
                $record->save();
                $data[self::AUTO_CODE_FIELDS[$kind]['field']] = $code;
            }
        }
        Audit::record('masters.'.$kind.'.saved', $record, ['branch_id' => $record->branch_id ?? ($kind === 'branches' ? $record->id : null), 'old' => $old, 'new' => $data]);

        return $record;
    }

    private function automaticCode(string $kind, $record): string
    {
        $meta = self::AUTO_CODE_FIELDS[$kind];
        $base = $meta['prefix'].'-'.str_pad((string) $record->getKey(), 6, '0', STR_PAD_LEFT);
        $code = $base;
        $suffix = 2;

        while ($record->newQuery()->where($meta['field'], $code)->whereKeyNot($record->getKey())->exists()) {
            $code = $base.'-'.$suffix++;
        }

        return $code;
    }
}
