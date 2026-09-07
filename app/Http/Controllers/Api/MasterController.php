<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Item;
use App\Models\Region;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\MasterService;
use App\Support\Access;
use App\Support\UiText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

class MasterController extends Controller
{
    public function lookups()
    {
        return response()->json(['regions' => Region::where('active', true)->get(['id', 'name']), 'branches' => Access::scope(Branch::where('active', true), 'id')->get(['id', 'name', 'region_id', 'code']), 'cost_centers' => CostCenter::where('active', true)->get(['id', 'name', 'code']), 'warehouses' => Access::scope(Warehouse::where('active', true))->get(['id', 'name', 'branch_id', 'code']), 'vehicles' => Access::scope(Vehicle::where('active', true))->get(['id', 'plate', 'model', 'year', 'color', 'vin', 'branch_id', 'cost_center_id', 'odometer']), 'suppliers' => Supplier::where('active', true)->get(['id', 'name', 'code']), 'items' => Item::where('active', true)->get(), 'categories' => config('rotana.categories'), 'photo_labels' => config('rotana.photo_labels'), 'ui' => UiText::frontend()]);
    }

    public function index(Request $r, string $kind)
    {
        if ($r->has('length')) {
            $r->merge(['length' => min(max((int) $r->input('length'), 1), 100)]);
        }
        $model = MasterService::MODELS[$kind] ?? abort(404);
        $permission = in_array($kind, ['suppliers', 'vehicles', 'items']) ? $kind.'.view' : 'masters.manage';
        Access::allow($permission);
        $q = $model::query();
        if (in_array($kind, ['vehicles', 'warehouses'])) {
            $q = Access::scope($q);
            if ($r->filled('branch_id')) {
                Access::branch($r->integer('branch_id'));
                $q->where('branch_id', $r->integer('branch_id'));
            }
        }
        if ($kind === 'branches') {
            $q = Access::scope($q, 'id');
        }
        if ($r->filled('id')) {
            $q->whereKey($r->integer('id'));
        }
        if ($kind === 'vehicles') {
            $q->with('branch:id,name', 'costCenter:id,name');
        }
        if ($kind === 'warehouses') {
            $q->with('branch:id,name');
        }

        return DataTables::eloquent($q)->escapeColumns([])->toJson();
    }

    public function store(Request $r, string $kind, MasterService $service)
    {
        return DB::transaction(fn () => response()->json($service->save($kind, $r->all()), 201));
    }

    public function update(Request $r, string $kind, int $id, MasterService $service)
    {
        return DB::transaction(fn () => $service->save($kind, $r->all(), $id));
    }
}
