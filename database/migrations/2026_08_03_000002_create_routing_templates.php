<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
		// MySQL does not roll back DDL. A failed first attempt can therefore
		// leave empty, half-created tables behind while this migration remains
		// pending. Since no application can use these tables until the migration
		// is recorded, rebuilding them here makes the migration safely retryable.
		Schema::dropIfExists('v2_server_route_profile');
		Schema::dropIfExists('v2_server_route_template');
		Schema::dropIfExists('v2_server_outbound_template');
		Schema::dropIfExists('v2_route_template');
		Schema::dropIfExists('v2_outbound_template');

        Schema::create('v2_outbound_template', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('protocol', 32);
            $table->text('settings');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('v2_route_template', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->json('rules');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('v2_server_outbound_template', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('outbound_template_id');
            $table->string('tag', 64);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['server_id', 'outbound_template_id'], 'srv_out_tpl_uq');
            $table->unique(['server_id', 'tag'], 'srv_out_tag_uq');
            $table->foreign('server_id', 'srv_out_server_fk')->references('id')->on('v2_server')->cascadeOnDelete();
            $table->foreign('outbound_template_id', 'srv_out_tpl_fk')->references('id')->on('v2_outbound_template')->cascadeOnDelete();
        });

        Schema::create('v2_server_route_template', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('route_template_id');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['server_id', 'route_template_id'], 'srv_route_tpl_uq');
            $table->foreign('server_id', 'srv_route_server_fk')->references('id')->on('v2_server')->cascadeOnDelete();
            $table->foreign('route_template_id', 'srv_route_tpl_fk')->references('id')->on('v2_route_template')->cascadeOnDelete();
        });

        Schema::create('v2_server_route_profile', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('outbound_template_id')->nullable();
            $table->string('name');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['server_id', 'name'], 'srv_profile_name_uq');
            $table->foreign('server_id', 'srv_profile_server_fk')->references('id')->on('v2_server')->cascadeOnDelete();
            $table->foreign('outbound_template_id', 'srv_profile_out_fk')->references('id')->on('v2_outbound_template')->nullOnDelete();
        });

        Schema::table('v2_server', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_server', 'config_version')) {
                $table->unsignedBigInteger('config_version')->default(1)->after('enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            if (Schema::hasColumn('v2_server', 'config_version')) {
                $table->dropColumn('config_version');
            }
        });
        Schema::dropIfExists('v2_server_route_profile');
        Schema::dropIfExists('v2_server_route_template');
        Schema::dropIfExists('v2_server_outbound_template');
        Schema::dropIfExists('v2_route_template');
        Schema::dropIfExists('v2_outbound_template');
    }
};
