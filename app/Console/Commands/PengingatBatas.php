<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Enums\StatusUsulan;
use App\Models\User;
use App\Models\Usulan;
use App\Notifications\UsulanBerubah;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/** Pengingat harian ke Dishub: usulan yang menunggu pemeriksaan melewati batas hari. */
class PengingatBatas extends Command
{
    protected $signature = 'usulan:pengingat';

    protected $description = 'Kabari Dishub tentang usulan yang menunggu pemeriksaan melewati batas hari';

    public function handle(): int
    {
        $batas = config('usuljalan.batas_hari_periksa');
        $lewat = Usulan::where('status', StatusUsulan::Diperiksa)
            ->where('diajukan_at', '<', now()->subDays($batas))
            ->oldest('diajukan_at')->get();

        if ($lewat->isEmpty()) {
            $this->info('Tidak ada usulan yang melewati batas.');

            return self::SUCCESS;
        }

        $lama = $lewat->first();
        Notification::send(
            User::where('role', Role::Dishub)->where('aktif', true)->get(),
            new UsulanBerubah($lama, "{$lewat->count()} usulan melewati batas {$batas} hari",
                "Paling lama: {$lama->nomor} · menunggu ".(int) $lama->diajukan_at->diffInDays().' hari', 'batas'),
        );
        $this->info("Pengingat dikirim untuk {$lewat->count()} usulan.");

        return self::SUCCESS;
    }
}
