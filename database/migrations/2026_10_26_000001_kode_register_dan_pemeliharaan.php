<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kode register per unit perlengkapan terpasang (JENIS-NNN-KEC-YY), penghitung nomor urut, dan riwayat pemeliharaan.
 * Satu usulan bisa menghasilkan banyak unit, jadi `usulan_item_id` tidak lagi unik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jenis_perlengkapan', function (Blueprint $table) {
            $table->string('kode', 4)->nullable()->unique();
            $table->boolean('per_unit')->default(true); // false = satu kode per ruas/paket (meter, set, lokasi)
        });

        Schema::table('kecamatan', function (Blueprint $table) {
            $table->string('kode', 4)->nullable()->unique();
        });

        Schema::table('perlengkapan_terpasang', function (Blueprint $table) {
            $table->string('kode', 24)->nullable()->unique()->after('id');
            $table->index('usulan_item_id', 'perlengkapan_terpasang_usulan_item_idx');
        });
        if (Schema::hasIndex('perlengkapan_terpasang', ['usulan_item_id'], 'unique')) {
            Schema::table('perlengkapan_terpasang', function (Blueprint $table) {
                $table->dropUnique(['usulan_item_id']);
            });
        }

        Schema::create('kode_register_urut', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jenis_perlengkapan_id')->constrained('jenis_perlengkapan')->cascadeOnDelete();
            $table->foreignId('kecamatan_id')->constrained('kecamatan')->cascadeOnDelete();
            $table->unsignedSmallInteger('tahun');
            $table->unsignedInteger('terakhir')->default(0);
            $table->unique(['jenis_perlengkapan_id', 'kecamatan_id', 'tahun'], 'kode_register_urut_unik');
        });

        Schema::create('pemeliharaan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('perlengkapan_terpasang_id')->constrained('perlengkapan_terpasang')->cascadeOnDelete();
            $table->date('tanggal');
            $table->string('kegiatan', 20); // rutin | pembersihan | perbaikan | penggantian | lainnya
            $table->text('catatan')->nullable();
            $table->foreignId('petugas_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('laporan_kerusakan_id')->nullable()->unique()->constrained('laporan_kerusakan')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['tanggal', 'id']);
        });

        Schema::create('pemeliharaan_foto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pemeliharaan_id')->constrained('pemeliharaan')->cascadeOnDelete();
            $table->string('path');
            $table->timestamps();
        });

        // jenis yang diukur per meter/set/lokasi tidak dipecah per unit
        DB::table('jenis_perlengkapan')->whereNotIn('satuan', ['unit', 'titik', 'buah'])->update(['per_unit' => false]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pemeliharaan_foto');
        Schema::dropIfExists('pemeliharaan');
        Schema::dropIfExists('kode_register_urut');
        Schema::table('perlengkapan_terpasang', function (Blueprint $table) {
            $table->dropIndex('perlengkapan_terpasang_usulan_item_idx');
            $table->dropUnique(['kode']);
            $table->dropColumn('kode');
        });
        // satu item usulan kembali satu aset: hanya bila belum ada item yang dipecah menjadi banyak unit
        $kembar = DB::table('perlengkapan_terpasang')->whereNotNull('usulan_item_id')->groupBy('usulan_item_id')->havingRaw('count(*) > 1')->exists();
        if (! $kembar) {
            Schema::table('perlengkapan_terpasang', fn (Blueprint $table) => $table->unique('usulan_item_id'));
        }
        Schema::table('kecamatan', function (Blueprint $table) {
            $table->dropUnique(['kode']);
            $table->dropColumn('kode');
        });
        Schema::table('jenis_perlengkapan', function (Blueprint $table) {
            $table->dropUnique(['kode']);
            $table->dropColumn(['kode', 'per_unit']);
        });
    }
};
