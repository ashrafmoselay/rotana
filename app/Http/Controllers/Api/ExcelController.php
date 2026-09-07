<?php

namespace App\Http\Controllers\Api;

use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Imports\MasterImport;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Services\MasterService;
use App\Services\OrderQueries;
use App\Support\Access;
use App\Support\Amounts;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class ExcelController extends Controller
{
    private const HEADERS = ['items' => ['sku', 'name', 'unit', 'track_stock', 'unit_cost', 'minimum'], 'suppliers' => ['code', 'name', 'phone', 'email', 'tax_number', 'iban', 'address'], 'vehicles' => ['plate', 'vin', 'model', 'year', 'color', 'odometer', 'branch_code', 'cost_center_code']];

    public function template(string $kind)
    {
        Access::allow('excel.import');
        abort_unless(isset(self::HEADERS[$kind]), 404);
        Access::allow(MasterService::permission($kind));

        return Excel::download(new TableExport(self::HEADERS[$kind], []), $kind.'-template.xlsx');
    }

    public function import(Request $r, string $kind)
    {
        Access::allow('excel.import');
        abort_unless(isset(self::HEADERS[$kind]), 404);
        Access::allow(MasterService::permission($kind));
        $r->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:5120']);
        $import = new MasterImport($kind);
        DB::transaction(function () use ($import, $r, $kind) {
            Excel::import($import, $r->file('file'));
            if (! $import->imported) {
                throw ValidationException::withMessages(['file' => 'لا يحتوي الملف على بيانات للاستيراد.']);
            }
            Audit::record('excel.imported', null, ['kind' => $kind, 'rows' => $import->imported]);
        });

        return ['message' => 'تم الاستيراد بالكامل', 'rows' => $import->imported];
    }

    public function export(Request $r, string $kind, OrderQueries $queries)
    {
        Access::allow('excel.export');
        $rows = [];
        $headers = [];
        if ($kind === 'orders') {
            Access::allow('orders.view');
            $q = $queries->query($r)->with('payment');
            abort_if($q->count() > 20000, 422, 'ضيّق نطاق التصدير إلى 20000 طلب أو أقل.');
            $headers = ['رقم الطلب', 'التاريخ', 'النوع', 'الفرع', 'المورد', 'السيارة', 'الإجمالي', 'المدفوع', 'الحالة'];
            $rows = $q->orderBy('id')->get()->map(fn ($o) => [$o->number, $o->date, config('rotana.categories')[$o->category], $o->branch_name, $o->supplier_name, $o->vehicle_plate, Amounts::money($o->total_minor), Amounts::money($o->payment?->amount_minor ?? 0), $o->status->label()]);
        } elseif (isset(self::HEADERS[$kind])) {
            Access::allow($kind.'.view');
            $headers = self::HEADERS[$kind];
            $q = match ($kind) {
                'vehicles' => Access::scope(Vehicle::with('branch', 'costCenter')),'suppliers' => Supplier::query(),'items' => Item::query()
            };
            abort_if($q->count() > 20000, 422, 'الحد الأقصى 20000 سجل.');
            $rows = $q->get()->map(fn ($x) => match ($kind) {
                'vehicles' => [$x->plate, $x->vin, $x->model, $x->year, $x->color, $x->odometer, $x->branch->code, $x->costCenter->code],'suppliers' => [$x->code, $x->name, $x->phone, $x->email, $x->tax_number, $x->iban, $x->address],'items' => [$x->sku, $x->name, $x->unit, $x->track_stock ? 1 : 0, Amounts::money($x->unit_cost_minor), Amounts::quantity($x->minimum_milli)]
            });
        } else {
            abort(404);
        }
        Audit::record('excel.exported', null, ['kind' => $kind, 'rows' => count($rows)]);

        return Excel::download(new TableExport($headers, $rows), $kind.'-'.today()->format('Y-m-d').'.xlsx');
    }
}
