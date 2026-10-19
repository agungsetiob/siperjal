<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perlengkapan terpasang = satu tabel sendiri. Yang terpasang lewat usulan punya `usulan_item_id`;
 * yang terpasang sebelum SIPERJAL (data lama, diimpor/diinput Dishub) tidak punya usulan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perlengkapan_terpasang', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jenis_perlengkapan_id')->constrained('jenis_perlengkapan');
            $table->unsignedInteger('jumlah')->default(1);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('alamat')->nullable();
            $table->foreignId('kecamatan_id')->nullable()->constrained('kecamatan')->nullOnDelete();
            $table->foreignId('desa_id')->nullable()->constrained('desa')->nullOnDelete();
            $table->date('terpasang_at')->nullable();
            $table->unsignedSmallInteger('tahun')->nullable(); // data lama yang hanya diketahui tahunnya
            $table->foreignId('usulan_item_id')->nullable()->unique()->constrained('usulan_item')->cascadeOnDelete();
            $table->text('catatan')->nullable();
            $table->string('batch', 32)->nullable()->index(); // id impor, untuk "Batalkan impor"
            $table->timestamps();
        });

        Schema::table('laporan_kerusakan', function (Blueprint $table) {
            $table->foreignId('perlengkapan_terpasang_id')->nullable()->after('usulan_item_id')->constrained('perlengkapan_terpasang')->nullOnDelete();
        });

        // item terpasang yang sudah ada → baris aset (titik = titik terpasang, atau titik usulan)
        DB::table('usulan_item as i')
            ->join('usulan as u', 'u.id', '=', 'i.usulan_id')
            ->where('i.status', 'terpasang')
            ->select('i.id', 'i.jenis_perlengkapan_id', 'i.jumlah', 'i.jumlah_terpasang', 'i.terpasang_at', 'i.lat_terpasang', 'i.lng_terpasang', 'i.catatan_terpasang', 'u.lat', 'u.lng', 'u.alamat', 'u.kecamatan_id', 'u.desa_id')
            ->get()
            ->each(function ($r) {
                $lat = $r->lat_terpasang ?? $r->lat;
                $lng = $r->lng_terpasang ?? $r->lng;
                if ($lat === null || $lng === null) {
                    return;
                }
                $id = DB::table('perlengkapan_terpasang')->insertGetId([
                    'jenis_perlengkapan_id' => $r->jenis_perlengkapan_id,
                    'jumlah' => $r->jumlah_terpasang ?? $r->jumlah,
                    'lat' => $lat,
                    'lng' => $lng,
                    'alamat' => $r->alamat,
                    'kecamatan_id' => $r->kecamatan_id,
                    'desa_id' => $r->desa_id,
                    'terpasang_at' => $r->terpasang_at,
                    'tahun' => $r->terpasang_at ? (int) substr($r->terpasang_at, 0, 4) : null,
                    'usulan_item_id' => $r->id,
                    'catatan' => $r->catatan_terpasang,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('laporan_kerusakan')->where('usulan_item_id', $r->id)->update(['perlengkapan_terpasang_id' => $id]);
            });
    }

    public function down(): void
    {
        Schema::table('laporan_kerusakan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('perlengkapan_terpasang_id');
        });
        Schema::dropIfExists('perlengkapan_terpasang');
    }
};
