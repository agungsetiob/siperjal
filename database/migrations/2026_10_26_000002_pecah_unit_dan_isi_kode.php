<?php

use App\Support\KodeRegister;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data lama: isi kode singkat jenis/kecamatan yang dikenal, pecah baris berjumlah > 1 (jenis per unit) menjadi satu baris per unit,
 * lalu beri kode berurutan menurut tanggal pasang. Baris asli tetap menjadi unit pertama (id tidak berubah), jadi tautan
 * /lapor/aset/{id} yang sudah tercetak dan laporan kerusakan lama tetap menunjuk ke unit pertama.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (KodeRegister::KODE_JENIS as $nama => $kode) {
            DB::table('jenis_perlengkapan')->where('nama', $nama)->whereNull('kode')->update(['kode' => $kode]);
        }
        foreach (KodeRegister::KODE_KECAMATAN as $nama => $kode) {
            DB::table('kecamatan')->where('nama', $nama)->whereNull('kode')->update(['kode' => $kode]);
        }

        $pecah = 0;
        $perUnit = DB::table('jenis_perlengkapan')->where('per_unit', true)->pluck('id');
        DB::table('perlengkapan_terpasang')->whereIn('jenis_perlengkapan_id', $perUnit)->where('jumlah', '>', 1)->orderBy('id')->get()
            ->each(function ($r) use (&$pecah) {
                $salinan = (array) $r;
                unset($salinan['id'], $salinan['kode']);
                for ($i = 1; $i < $r->jumlah; $i++) {
                    DB::table('perlengkapan_terpasang')->insert([...$salinan, 'jumlah' => 1]);
                    $pecah++;
                }
                DB::table('perlengkapan_terpasang')->where('id', $r->id)->update(['jumlah' => 1]);
            });

        $berkode = KodeRegister::lengkapi();
        $sisa = DB::table('perlengkapan_terpasang')->whereNull('kode')->count();
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "  unit baru hasil pecahan: {$pecah}, berkode: {$berkode}, belum berkode: {$sisa}\n";
        }
    }

    public function down(): void
    {
        // pemecahan tidak dibatalkan: hanya kode yang dikosongkan
        DB::table('perlengkapan_terpasang')->update(['kode' => null]);
        DB::table('kode_register_urut')->delete();
    }
};
