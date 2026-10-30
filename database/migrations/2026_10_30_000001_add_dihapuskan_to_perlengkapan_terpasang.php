<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penghapusan aset: perlengkapan yang rusak total/hilang/dibongkar dinonaktifkan (bukan dihapus),
 * jadi kode register dan riwayatnya tetap tersimpan untuk arsip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perlengkapan_terpasang', function (Blueprint $table) {
            $table->date('dihapuskan_at')->nullable()->index();
            $table->string('alasan_hapus', 20)->nullable();
            $table->text('catatan_hapus')->nullable();
            $table->string('foto_hapus')->nullable();
            $table->foreignId('dihapuskan_oleh')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('perlengkapan_terpasang', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dihapuskan_oleh');
            $table->dropIndex(['dihapuskan_at']);
            $table->dropColumn(['dihapuskan_at', 'alasan_hapus', 'catatan_hapus', 'foto_hapus']);
        });
    }
};
