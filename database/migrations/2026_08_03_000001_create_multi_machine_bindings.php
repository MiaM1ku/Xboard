<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_server_machine_binding')) {
            Schema::create('v2_server_machine_binding', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('server_id');
                $table->unsignedBigInteger('machine_id');
                $table->string('state', 16)->default('active');
                $table->unsignedBigInteger('desired_config_version')->default(1);
                $table->unsignedBigInteger('applied_config_version')->nullable();
                $table->text('last_error')->nullable();
                $table->unsignedInteger('last_seen_at')->nullable();
                $table->timestamps();

                $table->unique(['server_id', 'machine_id'], 'server_machine_binding_unique');
                $table->index(['machine_id', 'state']);
                $table->foreign('server_id')->references('id')->on('v2_server')->cascadeOnDelete();
                $table->foreign('machine_id')->references('id')->on('v2_server_machine')->cascadeOnDelete();
            });
        }

        Schema::table('v2_server_machine', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_server_machine', 'agent_version')) {
                $table->string('agent_version', 64)->nullable()->after('load_status');
            }
            if (!Schema::hasColumn('v2_server_machine', 'kernel_type')) {
                $table->string('kernel_type', 32)->nullable()->after('agent_version');
            }
            if (!Schema::hasColumn('v2_server_machine', 'capabilities')) {
                $table->json('capabilities')->nullable()->after('kernel_type');
            }
            if (!Schema::hasColumn('v2_server_machine', 'agent_instance_id')) {
                $table->uuid('agent_instance_id')->nullable()->after('capabilities');
            }
        });

        if (Schema::hasColumn('v2_server', 'machine_id')) {
            DB::table('v2_server')
                ->whereNotNull('machine_id')
                ->orderBy('id')
                ->chunkById(200, function ($servers): void {
                    $now = now();
                    $rows = [];
                    foreach ($servers as $server) {
                        $rows[] = [
                            'server_id' => $server->id,
                            'machine_id' => $server->machine_id,
                            'state' => 'active',
                            'desired_config_version' => 1,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($rows !== []) {
                        DB::table('v2_server_machine_binding')->insertOrIgnore($rows);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            foreach (['agent_instance_id', 'capabilities', 'kernel_type', 'agent_version'] as $column) {
                if (Schema::hasColumn('v2_server_machine', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('v2_server_machine_binding');
    }
};
