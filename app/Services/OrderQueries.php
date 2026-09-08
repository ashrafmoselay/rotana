<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Support\Access;
use Illuminate\Http\Request;

class OrderQueries
{
    public function query(Request $r, array $except = [])
    {
        $q = Access::scope(PurchaseOrder::query());
        foreach (['status', 'vehicle_id', 'supplier_id', 'category', 'branch_id'] as $f) {
            if (! in_array($f, $except, true) && $r->filled($f)) {
                $q->where($f, $r->input($f));
            }
        }
        if ($r->boolean('open')) {
            $q->whereIn('status', ['draft', 'accountant', 'manager', 'supervisor', 'matching', 'ready']);
        }
        if ($r->filled('from')) {
            $q->whereDate('date', '>=', $r->input('from'));
        }
        if ($r->filled('to')) {
            $q->whereDate('date', '<=', $r->input('to'));
        }

        return $q;
    }
}
