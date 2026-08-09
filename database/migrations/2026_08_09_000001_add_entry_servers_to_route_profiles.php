<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_route_profile', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_server_route_profile', 'entry_server_ids')) {
                $table->json('entry_server_ids')->nullable()->after('enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_route_profile', function (Blueprint $table) {
            if (Schema::hasColumn('v2_server_route_profile', 'entry_server_ids')) {
                $table->dropColumn('entry_server_ids');
            }
        });
    }
};
