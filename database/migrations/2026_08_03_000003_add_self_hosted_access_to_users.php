<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table): void {
            if (!Schema::hasColumn('v2_user', 'access_enabled')) {
                $table->boolean('access_enabled')->default(false)->index()->after('group_id');
            }
            if (!Schema::hasColumn('v2_user', 'access_note')) {
                $table->string('access_note', 255)->nullable()->after('access_enabled');
            }
        });

		// Preserve everyone who was usable immediately before this migration,
		// then move future authorization to the explicit access grant.
		DB::table('v2_user')
			->where('banned', 0)
			->where('transfer_enable', '>', 0)
			->where(function ($query): void {
				$query->whereNull('expired_at')->orWhere('expired_at', '>', time());
			})
			->update(['access_enabled' => 1]);
		DB::table('v2_user')->where('is_admin', 1)->update(['access_enabled' => 1]);
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table): void {
            if (Schema::hasColumn('v2_user', 'access_note')) {
                $table->dropColumn('access_note');
            }
            if (Schema::hasColumn('v2_user', 'access_enabled')) {
                $table->dropColumn('access_enabled');
            }
        });
    }
};
