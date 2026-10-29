<?php

use App\Models\LaporanKerusakan;
use Illuminate\Database\Migrations\Migration;

/** Laporan kerusakan yang sudah selesai sebelum ada riwayat pemeliharaan: jadikan catatan di riwayat unitnya. */
return new class extends Migration
{
    public function up(): void
    {
        $n = LaporanKerusakan::isiRiwayatDariLaporanSelesai();
        if ($n && app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "  catatan riwayat dari laporan selesai: {$n}\n";
        }
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('pemeliharaan')->whereNotNull('laporan_kerusakan_id')->delete();
    }
};
