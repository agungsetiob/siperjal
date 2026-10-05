<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usulan', function (Blueprint $table) {
            $table->id();
            $table->string('nomor')->nullable()->unique(); // diisi saat dikirim
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instansi_id')->nullable()->constrained('instansi')->nullOnDelete();
            $table->string('status')->default('draft')->index();

            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('alamat')->nullable();
            $table->foreignId('kecamatan_id')->nullable()->constrained('kecamatan')->nullOnDelete();
            $table->foreignId('desa_id')->nullable()->constrained('desa')->nullOnDelete();

            $table->json('kriteria_klaim')->nullable();
            $table->text('keterangan')->nullable();
            $table->string('penanggung_jawab')->nullable();
            $table->string('proposal_path')->nullable();
            $table->boolean('proposal_otomatis')->default(false);

            // hasil pemeriksaan Dishub
            $table->json('kriteria_dishub')->nullable();
            $table->unsignedTinyInteger('skor')->nullable();
            $table->string('status_jalan')->nullable();
            $table->foreignId('duplikat_dari_id')->nullable()->constrained('usulan')->nullOnDelete();

            // revisi
            $table->unsignedTinyInteger('revisi_ke')->default(0);
            $table->json('revisi_bagian')->nullable();
            $table->text('revisi_catatan')->nullable();

            $table->timestamp('diajukan_at')->nullable();
            $table->timestamp('diperiksa_at')->nullable();
            $table->timestamps();
        });

        Schema::create('usulan_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usulan_id')->constrained('usulan')->cascadeOnDelete();
            $table->foreignId('jenis_perlengkapan_id')->constrained('jenis_perlengkapan');
            $table->unsignedInteger('jumlah');
            $table->unsignedInteger('jumlah_disetujui')->nullable();
            $table->unsignedBigInteger('harga_satuan')->nullable(); // dikunci saat disetujui Bappeda
            $table->string('status')->default('menunggu')->index();
            $table->text('catatan_dishub')->nullable();
            $table->text('catatan_bappeda')->nullable();
            $table->unsignedSmallInteger('tahun_anggaran')->nullable();
            $table->unsignedSmallInteger('tahun_target')->nullable();
            $table->boolean('diubah')->default(false);
            $table->timestamp('diputuskan_at')->nullable();
            $table->unsignedInteger('jumlah_terpasang')->nullable();
            $table->date('terpasang_at')->nullable();
            $table->decimal('lat_terpasang', 10, 7)->nullable();
            $table->decimal('lng_terpasang', 10, 7)->nullable();
            $table->text('catatan_terpasang')->nullable();
            $table->timestamps();
        });

        Schema::create('usulan_foto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usulan_id')->constrained('usulan')->cascadeOnDelete();
            $table->foreignId('usulan_item_id')->nullable()->constrained('usulan_item')->cascadeOnDelete();
            $table->string('jenis')->default('lokasi'); // lokasi, hasil
            $table->string('path');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamps();
        });

        Schema::create('riwayat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usulan_id')->constrained('usulan')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('aksi');
            $table->text('keterangan')->nullable();
            $table->boolean('publik')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riwayat');
        Schema::dropIfExists('usulan_foto');
        Schema::dropIfExists('usulan_item');
        Schema::dropIfExists('usulan');
    }
};
