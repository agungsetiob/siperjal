<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('jabatan')->nullable()->after('role');
            $t->string('foto_path')->nullable();
            $t->boolean('notif_email')->default(true);
            $t->boolean('notif_wa')->default(true);
        });

        Schema::table('usulan_item', function (Blueprint $t) {
            $t->foreignId('diputuskan_oleh')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('usulan_item', function (Blueprint $t) {
            $t->dropConstrainedForeignId('diputuskan_oleh');
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['jabatan', 'foto_path', 'notif_email', 'notif_wa']);
        });
    }
};
