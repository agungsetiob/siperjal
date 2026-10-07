<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan kerusakan bisa di titik bebas (seperti usulan): jenis & lokasi disimpan di laporan sendiri,
 * tautan ke perlengkapan terpasang (usulan_item_id) jadi opsional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laporan_kerusakan', function (Blueprint $table) {
            $table->foreignId('usulan_item_id')->nullable()->change();
            $table->foreignId('jenis_perlengkapan_id')->nullable()->after('usulan_item_id')->constrained('jenis_perlengkapan')->nullOnDelete();
            $table->decimal('lat', 10, 7)->nullable()->after('jenis_perlengkapan_id');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
            $table->string('alamat')->nullable()->after('lng');
            $table->foreignId('kecamatan_id')->nullable()->after('alamat')->constrained('kecamatan')->nullOnDelete();
            $table->foreignId('desa_id')->nullable()->after('kecamatan_id')->constrained('desa')->nullOnDelete();
        });

        // laporan lama: salin jenis & titik dari perlengkapan yang dilaporkan
        DB::table('laporan_kerusakan as l')
            ->join('usulan_item as i', 'i.id', '=', 'l.usulan_item_id')
            ->join('usulan as u', 'u.id', '=', 'i.usulan_id')
            ->select('l.id', 'i.jenis_perlengkapan_id', 'i.lat_terpasang', 'i.lng_terpasang', 'u.lat', 'u.lng', 'u.alamat', 'u.kecamatan_id', 'u.desa_id')
            ->get()
            ->each(fn ($r) => DB::table('laporan_kerusakan')->where('id', $r->id)->update([
                'jenis_perlengkapan_id' => $r->jenis_perlengkapan_id,
                'lat' => $r->lat_terpasang ?? $r->lat,
                'lng' => $r->lng_terpasang ?? $r->lng,
                'alamat' => $r->alamat,
                'kecamatan_id' => $r->kecamatan_id,
                'desa_id' => $r->desa_id,
            ]));
    }

    public function down(): void
    {
        Schema::table('laporan_kerusakan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('desa_id');
            $table->dropConstrainedForeignId('kecamatan_id');
            $table->dropConstrainedForeignId('jenis_perlengkapan_id');
            $table->dropColumn(['lat', 'lng', 'alamat']);
        });
    }
};
