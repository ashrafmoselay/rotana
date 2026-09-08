<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceCard;
use App\Models\Vehicle;
use App\Support\Access;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class CardController extends Controller
{
    public function index(Request $r)
    {
        Access::allow('cards.view');
        $this->clampDataTableLength($r);

        $q = Access::scope(MaintenanceCard::with('vehicle:id,plate,branch_id,odometer,active', 'order:id,number,maintenance_card_id'));
        if ($r->filled('branch_id')) {
            Access::branch($r->integer('branch_id'));
            $q->where('branch_id', $r->integer('branch_id'));
        }
        $this->applyFilters($q, $r);

        return DataTables::eloquent($q)
            ->filterColumn('vehicle.plate', fn ($q, $keyword) => $q->whereHas('vehicle', fn ($q) => $q->where('plate', 'like', '%'.$keyword.'%')))
            ->orderColumn('vehicle.plate', function ($q, $order) {
                $q->orderBy(
                    Vehicle::select('plate')
                        ->whereColumn('vehicles.id', 'maintenance_cards.vehicle_id')
                        ->limit(1),
                    $order
                );
            })
            ->escapeColumns([])
            ->toJson();
    }

    public function summary(Request $r)
    {
        Access::allow('cards.view');
        $q = Access::scope(MaintenanceCard::query());
        $this->applyFilters($q, $r, false);

        $counts = (clone $q)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json(['counts' => collect(['pending', 'waiting_parts', 'in_progress', 'completed', 'closed'])
            ->mapWithKeys(fn ($status) => [$status => (int) ($counts[$status] ?? 0)])]);
    }

    public function store(Request $r)
    {
        Access::allow('cards.manage');
        $d = $r->validate(['vehicle_id' => 'required|exists:vehicles,id', 'date' => 'required|date_format:Y-m-d', 'type' => 'required|string|max:190', 'odometer' => 'required|integer|min:0|max:999999999', 'notes' => 'nullable|string|max:2000']);

        return DB::transaction(function () use ($d) {
            $v = Vehicle::lockForUpdate()->findOrFail($d['vehicle_id']);
            Access::branch($v->branch_id);
            abort_if($d['odometer'] < $v->odometer || ! $v->active, 422, 'تحقق من السيارة والعداد.');
            $card = MaintenanceCard::create($d + ['branch_id' => $v->branch_id, 'created_by' => auth()->id(), 'status' => 'pending']);
            $card->update(['number' => 'MC-'.now()->year.'-'.str_pad($card->id, 6, '0', STR_PAD_LEFT)]);
            $v->update(['odometer' => $d['odometer']]);
            Audit::record('cards.created', $card, ['branch_id' => $card->branch_id]);

            return response()->json($card, 201);
        });
    }

    public function update(Request $r, MaintenanceCard $card)
    {
        Access::allow('cards.manage');
        $d = $r->validate(['status' => ['required', Rule::in(['pending', 'waiting_parts', 'in_progress', 'completed', 'closed'])], 'notes' => 'nullable|string|max:2000']);

        return DB::transaction(function () use ($card, $d) {
            $card = MaintenanceCard::lockForUpdate()->findOrFail($card->id);
            Access::branch($card->branch_id);
            $stages = ['pending', 'waiting_parts', 'in_progress', 'completed', 'closed'];
            abort_unless(array_search($d['status'], $stages) === array_search($card->status, $stages) + 1, 422, 'انتقل إلى المرحلة التالية بالترتيب.');
            $card->update($d);
            Audit::record('cards.status_changed', $card, ['branch_id' => $card->branch_id, 'status' => $card->status]);

            return $card;
        });
    }

    public function edit(Request $r, MaintenanceCard $card)
    {
        Access::allow('cards.manage');
        $d = $r->validate([
            'date' => 'required|date_format:Y-m-d',
            'type' => 'required|string|max:190',
            'notes' => 'nullable|string|max:2000',
        ]);

        Access::branch($card->branch_id);
        $card->update($d);
        Audit::record('cards.updated', $card, ['branch_id' => $card->branch_id]);

        return $card->fresh('vehicle:id,plate');
    }

    public function destroy(MaintenanceCard $card)
    {
        Access::allow('cards.manage');
        Access::branch($card->branch_id);
        abort_if($card->order()->exists(), 422, 'لا يمكن حذف كارت مرتبط بطلب شراء.');

        Audit::record('cards.deleted', $card, ['branch_id' => $card->branch_id]);
        $card->delete();

        return response()->noContent();
    }

    private function applyFilters($q, Request $r, bool $includeStatus = true): void
    {
        if ($r->boolean('open')) {
            $q->where('status', '!=', 'closed');
        }
        if ($r->filled('status') && $includeStatus) {
            $q->where('status', $r->input('status'));
        }
        if ($r->filled('vehicle_id')) {
            $q->where('vehicle_id', $r->integer('vehicle_id'));
        }
        if ($r->filled('date_from')) {
            $q->whereDate('date', '>=', $r->input('date_from'));
        }
        if ($r->filled('date_to')) {
            $q->whereDate('date', '<=', $r->input('date_to'));
        }
    }

    private function clampDataTableLength(Request $r): void
    {
        if ($r->has('length')) {
            $r->merge(['length' => min(max((int) $r->input('length'), 1), 100)]);
        }
    }
}
