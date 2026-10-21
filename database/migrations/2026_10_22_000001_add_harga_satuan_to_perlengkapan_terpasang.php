<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Harga satuan aset terpasang (opsional). Dari usulan: harga yang dikunci Bappeda; data lama: diisi Dishub bila diketahui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perlengkapan_terpasang', function (Blueprint $table) {
            $table->unsignedBigInteger('harga_satuan')->nullable()->after('jumlah');
        });

        DB::table('usulan_item')->whereNotNull('harga_satuan')->get(['id', 'harga_satuan'])
            ->each(fn ($i) => DB::table('perlengkapan_terpasang')->where('usulan_item_id', $i->id)->update(['harga_satuan' => $i->harga_satuan]));
    }

    public function down(): void
    {
        Schema::table('perlengkapan_terpasang', function (Blueprint $table) {
            $table->dropColumn('harga_satuan');
        });
    }
};
