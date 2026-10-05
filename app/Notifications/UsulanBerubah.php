<?php

namespace App\Notifications;

use App\Models\Usulan;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Kabar perubahan usulan (lonceng + email).
 * jenis: baru, diperiksa, revisi, duplikat, disetujui, ditunda, terpasang, koreksi, info
 */
class UsulanBerubah extends Notification
{
    public function __construct(
        public Usulan $usulan,
        public string $judul,
        public ?string $isi = null,
        public string $jenis = 'info',
        public array $ekstra = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ($notifiable->notif_email ?? true) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(str_contains($this->judul, $this->usulan->nomor) ? $this->judul : "{$this->judul} · {$this->usulan->nomor}")
            ->view('mail.usulan', [
                'usulan' => $this->usulan,
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
            'usulan_id' => $this->usulan->id,
            'nomor' => $this->usulan->nomor,
            'judul' => $this->judul,
            'isi' => $this->isi,
            'jenis' => $this->jenis,
        ] + $this->ekstra;
    }
}
