<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laporan_kerusakan', function (Blueprint $table) {
            $table->id();
            $table->string('nomor')->unique();
            $table->foreignId('usulan_item_id')->constrained('usulan_item')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // pelapor masuk; kosong = pelapor publik
            $table->string('nama_pelapor')->nullable(); // pelapor publik (opsional)
            $table->string('kontak_email')->nullable(); // pelapor publik, untuk kabar perkembangan
            $table->string('kerusakan'); // mati, rusak, hilang, pudar, roboh
            $table->text('catatan')->nullable();
            $table->string('status')->default('baru')->index();
            $table->index(['usulan_item_id', 'status']);

            // penugasan (koordinator Dishub)
            $table->foreignId('petugas_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ditugaskan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->text('catatan_tugas')->nullable();
            $table->timestamp('ditugaskan_at')->nullable();

            // cek lokasi (petugas lapangan)
            $table->text('catatan_cek')->nullable();
            $table->decimal('lat_cek', 10, 7)->nullable();
            $table->decimal('lng_cek', 10, 7)->nullable();
            $table->timestamp('dicek_at')->nullable();

            // penutupan
            $table->string('alasan_tolak')->nullable();
            $table->foreignId('ditutup_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->text('catatan_selesai')->nullable();
            $table->timestamp('selesai_at')->nullable();
            $table->timestamps();
        });

        Schema::create('laporan_foto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laporan_kerusakan_id')->constrained('laporan_kerusakan')->cascadeOnDelete();
            $table->string('jenis')->default('pelapor'); // pelapor, cek, hasil
            $table->string('path');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laporan_foto');
        Schema::dropIfExists('laporan_kerusakan');
    }
};
