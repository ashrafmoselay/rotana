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
    public function index()
    {
        Access::allow('cards.view');

        return DataTables::eloquent(Access::scope(MaintenanceCard::with('vehicle', 'order:id,number,maintenance_card_id')))->escapeColumns([])->toJson();
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
}
