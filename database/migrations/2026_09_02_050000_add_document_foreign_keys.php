<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['supplier_invoices', 'payments'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreign('media_id')->references('id')->on('media')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        foreach (['supplier_invoices', 'payments'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign(['media_id']));
        }
    }
};
