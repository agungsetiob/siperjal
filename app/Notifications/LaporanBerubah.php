<?php

namespace App\Notifications;

use App\Models\LaporanKerusakan;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Kabar laporan kerusakan (lonceng + email).
 * jenis: kerusakan_baru, kerusakan_tugas, kerusakan_dikonfirmasi, kerusakan_ditolak, kerusakan_selesai
 */
class LaporanBerubah extends Notification
{
    public function __construct(
        public LaporanKerusakan $laporan,
        public string $judul,
        public ?string $isi = null,
        public string $jenis = 'info',
        public array $ekstra = [],
    ) {}

    public function via(object $notifiable): array
    {
        if ($notifiable instanceof \Illuminate\Notifications\AnonymousNotifiable) {
            return ['mail']; // pelapor tanpa akun
        }

        return ($notifiable->notif_email ?? true) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->judul} · {$this->laporan->nomor}")
            ->view('mail.laporan', [
                'laporan' => $this->laporan,
                'judul' => $this->judul,
                'isi' => $this->isi,
                'jenis' => $this->jenis,
                'ekstra' => $this->ekstra,
                'penerima' => $notifiable,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'laporan_id' => $this->laporan->id,
            'nomor' => $this->laporan->nomor,
            'judul' => $this->judul,
            'isi' => $this->isi,
            'jenis' => $this->jenis,
        ] + array_filter($this->ekstra);
    }
}
