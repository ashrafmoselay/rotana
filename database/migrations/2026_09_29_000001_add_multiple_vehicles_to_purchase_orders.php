<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_order_vehicles')) {
            Schema::create('purchase_order_vehicles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
                $table->foreignId('maintenance_card_id')->nullable()->unique()->constrained()->restrictOnDelete();
                $table->unsignedInteger('odometer')->nullable();
                $table->timestamps();
                $table->unique(['purchase_order_id', 'vehicle_id']);
            });
        }

        if (! Schema::hasColumn('order_lines', 'vehicle_id')) {
            Schema::table('order_lines', function (Blueprint $table) {
                $table->foreignId('vehicle_id')->nullable()->constrained()->restrictOnDelete();
            });
        }

        Schema::table('order_lines', function (Blueprint $table) {
            // MySQL uses the old composite unique key for the order foreign key.
            // Keep a dedicated index before replacing that key.
            $table->index('purchase_order_id');
            $table->dropUnique('order_lines_purchase_order_id_item_id_unique');
            $table->unique(['purchase_order_id', 'item_id', 'vehicle_id']);
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropUnique('order_lines_purchase_order_id_item_id_vehicle_id_unique');
            $table->dropConstrainedForeignId('vehicle_id');
            $table->unique(['purchase_order_id', 'item_id']);
            $table->dropIndex('order_lines_purchase_order_id_index');
        });

        Schema::dropIfExists('purchase_order_vehicles');
    }
};
