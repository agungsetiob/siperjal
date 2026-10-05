<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jenis_perlengkapan', function (Blueprint $table) {
            $table->string('deskripsi')->nullable()->after('nama');
        });

        Schema::create('pengaturan', function (Blueprint $table) {
            $table->string('kunci')->primary();
            $table->string('nilai')->nullable();
            $table->timestamps();
        });

        Schema::create('log_pengaturan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('keterangan');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_pengaturan');
        Schema::dropIfExists('pengaturan');
        Schema::table('jenis_perlengkapan', fn (Blueprint $table) => $table->dropColumn('deskripsi'));
    }
};
