<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versi awal pemeliharaan (tabel laporan_rusak, commit fcb5708) digantikan laporan_kerusakan (desain PM1–PM4).
 * Data yang sempat masuk dipindahkan, lalu tabel lama dihapus. File foto tetap di tempatnya (path ikut disalin).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('laporan_rusak')) {
            return;
        }

        DB::transaction(function () {
            $status = ['baru' => 'baru', 'diproses' => 'menunggu_perbaikan', 'selesai' => 'selesai', 'ditolak' => 'ditolak'];
            $jenis = ['miring' => 'roboh'];
            foreach (DB::table('laporan_rusak')->orderBy('id')->get() as $r) {
                if (DB::table('laporan_kerusakan')->where('nomor', $r->nomor)->exists()) {
                    continue;
                }
                $id = DB::table('laporan_kerusakan')->insertGetId([
                    'nomor' => $r->nomor,
                    'usulan_item_id' => $r->usulan_item_id,
                    'user_id' => null,
                    'nama_pelapor' => $r->nama_pelapor,
                    'kontak_email' => $r->kontak_email,
                    'kerusakan' => $jenis[$r->jenis] ?? $r->jenis,
                    'catatan' => $r->keterangan,
                    'status' => $status[$r->status] ?? 'baru',
                    'petugas_id' => $r->ditangani_oleh,
                    'dicek_at' => $r->status === 'baru' ? null : $r->diproses_at,
                    'alasan_tolak' => $r->status === 'ditolak' ? $r->catatan_dishub : null,
                    'catatan_selesai' => $r->status === 'selesai' ? $r->catatan_dishub : null,
                    'ditutup_oleh' => in_array($r->status, ['selesai', 'ditolak']) ? $r->ditangani_oleh : null,
                    'selesai_at' => $r->status === 'selesai' ? $r->selesai_at : null,
                    'created_at' => $r->created_at,
                    'updated_at' => $r->updated_at,
                ]);
                foreach (DB::table('laporan_rusak_foto')->where('laporan_rusak_id', $r->id)->get() as $f) {
                    DB::table('laporan_foto')->insert([
                        'laporan_kerusakan_id' => $id,
                        'jenis' => $f->jenis === 'perbaikan' ? 'hasil' : 'pelapor',
                        'path' => $f->path,
                        'lat' => $f->lat,
                        'lng' => $f->lng,
                        'created_at' => $f->created_at,
                        'updated_at' => $f->updated_at,
                    ]);
                }
            }
        });

        Schema::dropIfExists('laporan_rusak_foto');
        Schema::dropIfExists('laporan_rusak');
    }

    public function down(): void
    {
        // Tidak dikembalikan: data sudah pindah ke laporan_kerusakan.
    }
};
