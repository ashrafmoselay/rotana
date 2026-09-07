<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveOrderRequest;
use App\Models\PurchaseOrder;
use App\Services\OrderQueries;
use App\Services\PurchasingService;
use App\Support\Access;
use App\Support\Amounts;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class OrderController extends Controller
{
    public function __construct(private PurchasingService $service) {}

    public function index(Request $r, OrderQueries $queries)
    {
        Access::allow('orders.view');
        if ($r->has('length')) {
            $r->merge(['length' => min(max((int) $r->input('length'), 1), 100)]);
        }

        return DataTables::eloquent($queries->query($r)->with('payment'))
            ->filterColumn('total', fn ($q, $keyword) => $q->where('total_minor', (int) round(((float) $keyword) * 100)))
            ->filterColumn('status_label', fn ($q, $keyword) => $q->where('status', $keyword))
            ->orderColumn('total', 'total_minor $1')
            ->orderColumn('status_label', 'status $1')
            ->addColumn('total', fn ($o) => Amounts::money($o->total_minor))
            ->addColumn('paid', fn ($o) => Amounts::money($o->payment?->amount_minor ?? 0))
            ->addColumn('status_label', fn ($o) => $o->status->label())
            ->escapeColumns([])
            ->toJson();
    }

    public function store(SaveOrderRequest $r)
    {
        return response()->json($this->service->save($r->validated()), 201);
    }

    public function update(SaveOrderRequest $r, PurchaseOrder $order)
    {
        return $this->service->save($r->validated(), $order);
    }

    public function show(PurchaseOrder $order)
    {
        Access::allow('orders.view');
        Access::branch($order->branch_id);
        $order->load('lines.item', 'approvals.user:id,name', 'receipt.lines', 'invoice.lines', 'payment', 'media', 'vehicle', 'creator:id,name');
        $data = $order->toArray();
        $data['matched'] = $this->service->matches($order);
        $data['status_label'] = $order->status->label();
        $data['media'] = $order->media->map(fn ($m) => ['id' => $m->id, 'name' => $m->file_name, 'collection' => $m->collection_name, 'label' => $m->getCustomProperty('label'), 'mime' => $m->mime_type, 'url' => route('media.show', $m->id), 'size' => $m->size]);

        return response()->json($data);
    }

    public function action(Request $r, PurchaseOrder $order, string $action)
    {
        $r->validate(['reason' => 'nullable|string|max:2000']);

        return $this->service->action($order, $action, $r->input('reason'));
    }

    private function lineRules(): array
    {
        return ['date' => 'required|date_format:Y-m-d', 'lines' => 'required|array|min:1|max:100', 'lines.*.order_line_id' => 'required|integer|distinct', 'lines.*.quantity' => ['required', 'numeric', 'min:0', 'max:1000000', 'regex:/^\d+(\.\d{1,3})?$/']];
    }

    public function receipt(Request $r, PurchaseOrder $order)
    {
        return $this->service->receive($order, $r->validate($this->lineRules()));
    }

    public function invoice(Request $r, PurchaseOrder $order)
    {
        return $this->service->invoice($order, $r->validate($this->lineRules() + ['number' => 'required|string|max:100', 'total' => 'required|numeric|min:0|max:999999999', 'media_id' => 'required|integer']));
    }

    public function payment(Request $r, PurchaseOrder $order)
    {
        return $this->service->pay($order, $r->validate(['reference' => 'required|string|max:100|unique:payments,reference', 'date' => 'required|date_format:Y-m-d', 'amount' => 'required|numeric|min:0|max:999999999', 'media_id' => 'required|integer']));
    }
}
