<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('booking_requests')->whereIn('status', ['accepted', 'confirmed'])->update(['status' => 'approved']);
            DB::table('booking_requests')->where('status', 'declined')->update(['status' => 'rejected']);

            // The current flow cannot act on time proposals from the previous workflow.
            DB::table('booking_requests')->where('status', 'times_proposed')->update(['status' => 'expired']);
        });
    }

    public function down(): void
    {
        // The previous status cannot be recovered after merging values.
    }
};
