<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laporan kerusakan perlengkapan yang sudah terpasang (pemeliharaan)
        Schema::create('laporan_rusak', function (Blueprint $table) {
            $table->id();
            $table->string('nomor')->unique(); // LPR-2026-00001
            $table->foreignId('usulan_item_id')->constrained('usulan_item')->cascadeOnDelete();
            $table->string('jenis'); // JenisKerusakan
            $table->text('keterangan')->nullable();
            $table->string('nama_pelapor')->nullable();
            $table->string('kontak_email')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('status')->default('baru'); // StatusLaporan
            $table->text('catatan_dishub')->nullable();
            $table->foreignId('ditangani_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diproses_at')->nullable();
            $table->timestamp('selesai_at')->nullable();
            $table->timestamps();
            $table->index(['usulan_item_id', 'status']);
        });

        Schema::create('laporan_rusak_foto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laporan_rusak_id')->constrained('laporan_rusak')->cascadeOnDelete();
            $table->string('jenis')->default('laporan'); // laporan, perbaikan
            $table->string('path');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laporan_rusak_foto');
        Schema::dropIfExists('laporan_rusak');
    }
};
