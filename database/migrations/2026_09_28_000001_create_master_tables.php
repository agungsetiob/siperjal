<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kecamatan', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->timestamps();
        });

        Schema::create('desa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kecamatan_id')->constrained('kecamatan')->cascadeOnDelete();
            $table->string('nama');
            $table->timestamps();
        });

        Schema::create('ruas_jalan', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('status')->nullable(); // primer, sekunder, lokal, lingkungan
            $table->string('kewenangan')->nullable(); // nasional, provinsi, kabupaten, desa
            $table->timestamps();
        });

        Schema::create('instansi', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('jenis'); // desa, kecamatan, sekolah, opd, lainnya
            $table->foreignId('kecamatan_id')->nullable()->constrained('kecamatan')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('jenis_perlengkapan', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('ikon'); // nama ikon lucide
            $table->string('warna')->default('#4556A9'); // warna pin di peta
            $table->string('satuan');
            $table->unsignedBigInteger('harga_satuan')->default(0);
            $table->boolean('aktif')->default(true);
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('pengusul')->after('email'); // pengusul, dishub, bappeda, admin
            $table->string('no_hp')->nullable()->after('role');
            $table->foreignId('instansi_id')->nullable()->after('no_hp')->constrained('instansi')->nullOnDelete();
            $table->boolean('aktif')->default(true)->after('instansi_id');
            $table->string('password')->nullable()->change();
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('code');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('instansi_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'no_hp', 'aktif']));
        Schema::dropIfExists('jenis_perlengkapan');
        Schema::dropIfExists('instansi');
        Schema::dropIfExists('ruas_jalan');
        Schema::dropIfExists('desa');
        Schema::dropIfExists('kecamatan');
    }
};
