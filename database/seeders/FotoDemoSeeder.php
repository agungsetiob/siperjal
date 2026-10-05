<?php

namespace Database\Seeders;

use App\Enums\StatusItem as SI;
use App\Models\Usulan;
use App\Models\UsulanItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Foto contoh untuk data demo: foto lokasi tiap usulan & foto hasil tiap item terpasang.
 * Hanya melengkapi yang belum punya foto, jadi aman dijalankan ulang:
 * `php artisan db:seed --class=FotoDemoSeeder`
 */
class FotoDemoSeeder extends Seeder
{
    public function run(): void
    {
        $lokasi = glob(database_path('seeders/foto/lokasi-*.jpg'));
        $hasil = glob(database_path('seeders/foto/hasil-*.jpg'));

        Usulan::whereDoesntHave('foto', fn ($q) => $q->where('jenis', 'lokasi'))->whereNotNull('lat')->get()
            ->each(function (Usulan $u) use ($lokasi) {
                // 1–3 foto per usulan, urutan berbeda tiap usulan
                foreach (range(0, $u->id % 3) as $k) {
                    $this->salin($u, $lokasi[($u->id * 2 + $k) % count($lokasi)], 'lokasi', $u->lat, $u->lng, waktu: $u->diajukan_at);
                }
            });

        UsulanItem::with('usulan')->where('status', SI::Terpasang)
            ->whereDoesntHave('fotoHasil')->get()
            ->each(fn (UsulanItem $i) => $this->salin($i->usulan, $hasil[$i->id % count($hasil)], 'hasil',
                $i->lat_terpasang ?? $i->usulan->lat, $i->lng_terpasang ?? $i->usulan->lng, $i->id, $i->terpasang_at));
    }

    private function salin(Usulan $u, string $sumber, string $jenis, ?float $lat, ?float $lng, ?int $itemId = null, $waktu = null): void
    {
        $path = "usulan/{$u->id}/".uniqid($jenis.'-').'.jpg';
        Storage::disk('public')->put($path, file_get_contents($sumber));
        $foto = $u->foto()->create([
            'usulan_item_id' => $itemId,
            'jenis' => $jenis,
            'path' => $path,
            // titik foto sedikit bergeser dari pin, seperti GPS HP sungguhan (± 10 m)
            'lat' => $lat ? $lat + mt_rand(-8, 8) / 100000 : null,
            'lng' => $lng ? $lng + mt_rand(-8, 8) / 100000 : null,
        ]);
        // tanggal foto = saat diajukan / dipasang, bukan saat seeder dijalankan
        if ($waktu) {
            $foto->forceFill(['created_at' => $waktu, 'updated_at' => $waktu])->saveQuietly();
        }
    }
}
