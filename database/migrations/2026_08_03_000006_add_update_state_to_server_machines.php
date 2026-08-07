<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_server_machine', 'update_request_id')) {
                $table->uuid('update_request_id')->nullable();
                $table->string('update_version', 64)->nullable();
                $table->string('update_status', 24)->nullable();
                $table->text('update_message')->nullable();
                $table->string('update_from_instance_id', 64)->nullable();
                $table->unsignedInteger('update_requested_at')->nullable();
                $table->unsignedInteger('update_started_at')->nullable();
                $table->unsignedInteger('update_completed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $columns = [
                'update_request_id', 'update_version', 'update_status', 'update_message',
                'update_from_instance_id', 'update_requested_at', 'update_started_at',
                'update_completed_at',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('v2_server_machine', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
