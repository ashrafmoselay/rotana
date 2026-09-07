<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('order') ? 'orders.update' : 'orders.create');
    }

    public function rules(): array
    {
        return ['category' => ['required', Rule::in(array_keys(config('rotana.categories')))], 'branch_id' => 'required|integer|exists:branches,id', 'cost_center_id' => 'required|integer|exists:cost_centers,id', 'supplier_id' => 'required|integer|exists:suppliers,id', 'vehicle_id' => 'nullable|integer|exists:vehicles,id', 'warehouse_id' => 'nullable|integer|exists:warehouses,id', 'maintenance_card_id' => 'nullable|integer|exists:maintenance_cards,id', 'date' => 'required|date_format:Y-m-d', 'priority' => ['required', Rule::in(['normal', 'urgent', 'critical'])], 'quote_number' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:5000', 'tax_percent' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'], 'lines' => 'required|array|min:1|max:100', 'lines.*.item_id' => 'required|integer|distinct|exists:items,id', 'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000', 'regex:/^\d+(\.\d{1,3})?$/'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:10000000', 'regex:/^\d+(\.\d{1,2})?$/']];
    }

    public function attributes(): array
    {
        return trans('validation.attributes');
    }
}
