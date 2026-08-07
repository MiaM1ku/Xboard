<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_user_node_traffic')) {
            return;
        }

        Schema::create('v2_user_node_traffic', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('route_profile_id')->nullable();
            $table->unsignedBigInteger('outbound_template_id')->nullable();
            // Empty means the node-wide total. A UUID means one credential route.
            $table->string('profile_uuid', 64)->default('');
            $table->unsignedBigInteger('u')->default(0);
            $table->unsignedBigInteger('d')->default(0);
            $table->char('record_type', 1)->default('d');
            $table->unsignedInteger('record_at');
            $table->timestamps();

            $table->unique(
                ['user_id', 'server_id', 'profile_uuid', 'record_type', 'record_at'],
                'user_node_profile_period_uq'
            );
            $table->index(['record_at', 'record_type'], 'user_node_period_idx');
            $table->index(['server_id', 'record_at'], 'user_node_server_idx');
            $table->index(['outbound_template_id', 'record_at'], 'user_node_outbound_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_user_node_traffic');
    }
};
