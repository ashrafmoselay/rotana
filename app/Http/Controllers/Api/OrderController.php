<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveOrderRequest;
use App\Models\PurchaseOrder;
use App\Services\OrderQueries;
use App\Services\PurchasingService;
use App\Support\Access;
use App\Support\Amounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
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

        return DataTables::eloquent($queries->query($r)->with(['payment', 'orderVehicles.vehicle:id,plate']))
            ->filterColumn('total', fn ($q, $keyword) => $q->where('total_minor', (int) round(((float) $keyword) * 100)))
            ->filterColumn('status_label', fn ($q, $keyword) => $q->where('status', $keyword))
            ->filterColumn('vehicles', fn ($q, $keyword) => $q->where(fn ($orders) => $orders->where('vehicle_plate', 'like', "%{$keyword}%")->orWhereHas('orderVehicles.vehicle', fn ($vehicles) => $vehicles->where('plate', 'like', "%{$keyword}%"))))
            ->orderColumn('total', 'total_minor $1')
            ->orderColumn('status_label', 'status $1')
            ->addColumn('total', fn ($o) => Amounts::money($o->total_minor))
            ->addColumn('vehicles', fn ($o) => $o->orderVehicles->pluck('vehicle.plate')->filter()->implode('، ') ?: $o->vehicle_plate)
            ->addColumn('vehicles_count', fn ($o) => $o->orderVehicles->count() ?: ($o->vehicle_id ? 1 : 0))
            ->addColumn('paid', fn ($o) => Amounts::money($o->payment?->amount_minor ?? 0))
            ->addColumn('status_label', fn ($o) => $o->status->label())
            ->escapeColumns([])
            ->toJson();
    }

    public function summary(Request $r, OrderQueries $queries)
    {
        Access::allow('orders.view');

        $categoryQuery = $queries->query($r, ['category']);
        $statusQuery = $queries->query($r, ['status']);
        $categoryTotal = (clone $categoryQuery)->count();
        $statusTotal = (clone $statusQuery)->count();
        $categoryCounts = $categoryQuery->selectRaw('category, COUNT(*) as total')->groupBy('category')->pluck('total', 'category');
        $statusCounts = $statusQuery->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'categories' => collect([['category' => 'all', 'label' => 'الكل', 'count' => $categoryTotal]])->concat(collect(config('rotana.categories'))->map(fn ($label, $category) => [
                'category' => $category, 'label' => $label, 'count' => (int) ($categoryCounts[$category] ?? 0),
            ]))->values(),
            'statuses' => collect([['status' => 'all', 'label' => 'الكل', 'count' => $statusTotal]])->concat(collect(config('rotana.statuses', []))->map(fn ($label, $status) => [
                'status' => $status, 'label' => $label, 'count' => (int) ($statusCounts[$status] ?? 0),
            ]))->values(),
        ]);
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
        $order->load('lines.item', 'lines.vehicle:id,plate,model', 'orderVehicles.vehicle:id,plate,model,year,color', 'orderVehicles.maintenanceCard:id,number,odometer', 'approvals.user:id,name', 'receipt.lines', 'invoice.lines', 'payment', 'media', 'vehicle', 'creator:id,name', 'supplier:id,name,iban', 'maintenanceCard:id,odometer');
        $data = $order->toArray();
        $data['matched'] = $this->service->matches($order);
        $data['requires_receipt'] = $order->requiresReceipt();
        $data['can_record_documents'] = $order->canRecordDocuments();
        $data['status_label'] = $order->status->label();
        $data['media'] = $order->media->map(fn ($m) => ['id' => $m->id, 'name' => $m->file_name, 'collection' => $m->collection_name, 'label' => $m->getCustomProperty('label'), 'vehicle_id' => $m->getCustomProperty('vehicle_id'), 'mime' => $m->mime_type, 'url' => route('media.show', $m->id), 'size' => $m->size]);

        return response()->json($data);
    }

    public function transferPdf(PurchaseOrder $order)
    {
        Access::allow('orders.view');
        Access::branch($order->branch_id);

        return DB::transaction(function () use ($order) {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            Access::branch($order->branch_id);
            if ($order->status !== OrderStatus::Ready || $order->payment()->exists() || ! $this->service->readyForPayment($order)) {
                throw ValidationException::withMessages(['order' => 'يمكن تحميل مستند التحويل لطلب جاهز للتحويل وغير مدفوع ومستوفٍ لمتطلبات توقيت الدفع فقط.']);
            }
            $order->load(['supplier' => fn ($q) => $q->lockForUpdate(), 'approvals' => fn ($q) => $q->orderBy('id')->with('user:id,name'), 'creator:id,name']);
            if (! filled($order->supplier?->iban)) {
                throw ValidationException::withMessages(['order' => 'أكمل رقم الآيبان في بيانات المورد / المستفيد قبل تحميل مستند التحويل.']);
            }

            $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'default_font' => 'dejavusans', 'default_font_size' => 10,
                'margin_top' => 12, 'margin_bottom' => 18, 'margin_left' => 12, 'margin_right' => 12,
                'tempDir' => storage_path('app/private/mpdf')]);
            $pdf->SetDirectionality('rtl');
            $pdf->SetTitle('طلب جاهز للتحويل - '.$order->number);
            $pdf->SetHTMLFooter('<div style="text-align:center;font-size:9pt;color:#64748b">روتانا | صفحة {PAGENO} من {nbpg}</div>');
            $pdf->WriteHTML(view('orders.transfer-pdf', ['order' => $order, 'generatedAt' => now()])->render());

            return response($pdf->Output('', Destination::STRING_RETURN), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="order-'.$order->id.'-ready.pdf"',
                'Cache-Control' => 'private, no-store',
            ]);
        });
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
