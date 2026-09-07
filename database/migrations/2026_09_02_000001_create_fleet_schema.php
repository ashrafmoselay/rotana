<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('region_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('code')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('cost_centers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('active')->default(true);
            $t->boolean('all_branches')->default(false);
        });
        Schema::create('branch_user', function (Blueprint $t) {
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['branch_id', 'user_id']);
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('code')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('tax_number')->nullable();
            $t->string('iban')->nullable();
            $t->text('address')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('vehicles', function (Blueprint $t) {
            $t->id();
            $t->string('plate');
            $t->string('plate_key')->unique();
            $t->string('vin')->nullable()->unique();
            $t->string('model');
            $t->unsignedSmallInteger('year');
            $t->string('color');
            $t->unsignedBigInteger('odometer')->default(0);
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('cost_center_id')->constrained()->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('items', function (Blueprint $t) {
            $t->id();
            $t->string('sku')->unique();
            $t->string('name');
            $t->string('unit')->default('قطعة');
            $t->boolean('track_stock')->default(true);
            $t->unsignedBigInteger('unit_cost_minor')->default(0);
            $t->unsignedBigInteger('minimum_milli')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('stock_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('item_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('quantity_milli')->default(0);
            $t->timestamps();
            $t->unique(['warehouse_id', 'item_id']);
        });
        Schema::create('maintenance_cards', function (Blueprint $t) {
            $t->id();
            $t->string('number')->nullable()->unique();
            $t->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->date('date');
            $t->string('type');
            $t->string('status')->default('pending');
            $t->unsignedBigInteger('odometer');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['branch_id', 'status']);
        });
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number')->nullable()->unique();
            $t->string('category')->index();
            $t->string('status')->default('draft')->index();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('cost_center_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('vehicle_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('maintenance_card_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->date('date');
            $t->string('priority')->default('normal');
            $t->string('quote_number')->nullable();
            $t->text('notes')->nullable();
            $t->string('branch_name');
            $t->string('region_name');
            $t->string('supplier_name');
            $t->string('vehicle_plate')->nullable();
            $t->unsignedBigInteger('subtotal_minor')->default(0);
            $t->unsignedInteger('tax_basis_points')->default(0);
            $t->unsignedBigInteger('tax_minor')->default(0);
            $t->unsignedBigInteger('total_minor')->default(0);
            $t->timestamps();
            $t->index(['branch_id', 'status', 'date']);
        });
        Schema::create('order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained()->restrictOnDelete();
            $t->string('description');
            $t->string('sku');
            $t->unsignedBigInteger('quantity_milli');
            $t->unsignedBigInteger('unit_price_minor');
            $t->unsignedBigInteger('total_minor');
            $t->timestamps();
            $t->unique(['purchase_order_id', 'item_id']);
        });
        Schema::create('approval_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('from_status');
            $t->string('to_status');
            $t->string('action');
            $t->text('reason')->nullable();
            $t->timestamps();
        });
        Schema::create('receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->unique()->constrained()->restrictOnDelete();
            $t->string('number')->unique();
            $t->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->date('date');
            $t->timestamps();
        });
        Schema::create('receipt_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_line_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('quantity_milli');
            $t->unique(['receipt_id', 'order_line_id']);
        });
        Schema::create('supplier_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->string('number');
            $t->date('date');
            $t->unsignedBigInteger('total_minor');
            $t->unsignedBigInteger('media_id');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['supplier_id', 'number']);
        });
        Schema::create('invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_line_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('quantity_milli');
            $t->unique(['supplier_invoice_id', 'order_line_id']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->unique()->constrained()->restrictOnDelete();
            $t->string('reference')->unique();
            $t->date('date');
            $t->unsignedBigInteger('amount_minor');
            $t->unsignedBigInteger('media_id')->unique();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->string('number')->nullable()->unique();
            $t->uuid('request_key')->unique();
            $t->string('type')->index();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('destination_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $t->foreignId('vehicle_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('source_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('odometer')->nullable();
            $t->date('date');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['warehouse_id', 'date']);
        });
        Schema::create('stock_movement_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_movement_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained()->restrictOnDelete();
            $t->bigInteger('quantity_milli');
            $t->unsignedBigInteger('unit_cost_minor');
            $t->unique(['stock_movement_id', 'item_id']);
        });
    }

    public function down(): void
    {
        foreach (['stock_movement_lines', 'stock_movements', 'payments', 'invoice_lines', 'supplier_invoices', 'receipt_lines', 'receipts', 'approval_events', 'order_lines', 'purchase_orders', 'maintenance_cards', 'stock_balances', 'items', 'vehicles', 'suppliers', 'warehouses', 'branch_user', 'cost_centers', 'branches', 'regions'] as $table) {
            Schema::dropIfExists($table);
        }Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['active', 'all_branches']));
    }
};
