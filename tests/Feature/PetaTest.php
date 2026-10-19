<?php

namespace Tests\Feature;

use App\Models\Pengaturan;
use App\Models\User;
use App\Support\PetaConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class PetaTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const KUNCI = 'AIzaSyTestKeyTestKeyTestKeyTestKey12345';

    private function admin(): User
    {
        return User::where('email', 'admin@usuljalan.test')->firstOrFail();
    }

    public function test_bawaan_memakai_openstreetmap(): void
    {
        $this->assertSame('osm', PetaConfig::penyedia());
        $this->get('/')->assertOk()->assertSee('window.PETA', false)->assertDontSee('AIza', false);
    }

    public function test_google_tanpa_kunci_tetap_openstreetmap(): void
    {
        PetaConfig::simpan('google');
        $this->assertSame('google', PetaConfig::pilihan());
        $this->assertSame('osm', PetaConfig::penyedia());
        $this->assertNull(PetaConfig::untukJs()['googleKey']);
    }

    public function test_admin_memilih_google_setelah_kunci_diisi_dan_kunci_terenkripsi(): void
    {
        $this->actingAs($this->admin());

        Livewire::test('pages::pengaturan')
            ->call('ubahPeta', 'google')
            ->assertDispatched('toast', gagal: true)
            ->set('petaKunci', self::KUNCI)
            ->call('ubahPeta', 'google')
            ->assertSet('petaKunci', '');

        $this->assertSame('google', PetaConfig::penyedia());
        $this->assertSame(self::KUNCI, PetaConfig::untukJs()['googleKey']);
        $this->assertNotSame(self::KUNCI, Pengaturan::ambil('peta_google_kunci'));
        $this->get('/')->assertSee(self::KUNCI, false);
    }

    public function test_kunci_dengan_format_salah_ditolak_dan_kunci_kosong_menghapus(): void
    {
        $this->actingAs($this->admin());
        PetaConfig::simpan('google', self::KUNCI);

        Livewire::test('pages::pengaturan')->set('petaKunci', 'x y')->assertDispatched('toast', gagal: true);
        $this->assertSame(self::KUNCI, PetaConfig::kunci());

        Cache::flush();
        Livewire::test('pages::pengaturan')->set('petaKunci', ' ');
        $this->assertNull(PetaConfig::kunci());
        $this->assertSame('osm', PetaConfig::penyedia());
    }

    public function test_hanya_admin_boleh_mengatur_peta(): void
    {
        $this->actingAs(User::where('email', 'desa@usuljalan.test')->firstOrFail());
        $this->get('/pengaturan')->assertForbidden();
    }
}
