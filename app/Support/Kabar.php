<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use App\Models\Usulan;
use App\Notifications\UsulanBerubah;
use Illuminate\Support\Facades\Notification;

/** Kirim kabar perubahan usulan ke pengusul dan/atau role tertentu. */
class Kabar
{
    /** Ikon & warna notifikasi laporan kerusakan (lonceng & halaman notifikasi). */
    public const GAYA_KERUSAKAN = [
        'kerusakan_baru' => ['wrench', 'bg-accent-soft text-accent'],
        'kerusakan_tugas' => ['user-round-check', 'bg-accent-soft text-accent'],
        'kerusakan_dikonfirmasi' => ['clipboard-check', 'bg-warning-bg text-warning'],
        'kerusakan_ditolak' => ['circle-x', 'bg-danger-bg text-danger'],
        'kerusakan_selesai' => ['badge-check', 'bg-success-bg text-success'],
    ];

    /** @param  Role[]  $roles */
    public static function kirim(Usulan $u, string $judul, ?string $isi = null, string $jenis = 'info', array $ekstra = [], array $roles = [], bool $pengusul = true): void
    {
        $penerima = User::where('aktif', true)->whereIn('role', $roles)->get();
        if ($pengusul) {
            $penerima = $penerima->merge($u->instansi_id ? User::where('instansi_id', $u->instansi_id)->get() : [$u->user]);
        }
        Notification::send(
            $penerima->unique('id')->reject(fn ($p) => $p->id === auth()->id()),
            new UsulanBerubah($u, $judul, $isi, $jenis, $ekstra),
        );
    }
}
