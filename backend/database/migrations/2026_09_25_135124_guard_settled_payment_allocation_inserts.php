<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::unprepared("CREATE TRIGGER settled_allocation_no_insert BEFORE INSERT ON payment_allocations FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM payments WHERE id = NEW.payment_id AND status = 'settled') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settled allocations are immutable'; END IF; END");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS settled_allocation_no_insert');
    }
};
