<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dishub meminta admin menambah jenis perlengkapan yang belum ada di Pengaturan (lonceng + email ke admin).
 */
class PermintaanJenis extends Notification
{
    public function __construct(
        public User $peminta,
        public string $nama,
        public ?string $satuan = null,
        public ?string $catatan = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ($notifiable->notif_email ?? true) ? ['database', 'mail'] : ['database'];
    }

    private function isi(): string
    {
        return collect([
            "“{$this->nama}”".($this->satuan ? " (satuan {$this->satuan})" : ''),
            'dari '.$this->peminta->name,
            $this->catatan,
        ])->filter()->implode(' · ');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Permintaan jenis perlengkapan baru: {$this->nama} · SIPERJAL")
            ->greeting('Halo '.$notifiable->name.',')
            ->line('Ada permintaan menambah jenis perlengkapan di SIPERJAL:')
            ->line($this->isi())
            ->line('Jenis ini dibutuhkan untuk mencatat perlengkapan terpasang. Setelah ditambahkan, jenisnya langsung bisa dipilih.')
            ->action('Buka Pengaturan → Perlengkapan', route('pengaturan', ['tab' => 'perlengkapan']));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'jenis' => 'minta_jenis',
            'judul' => 'Permintaan jenis perlengkapan baru',
            'nomor' => $this->nama,
            'isi' => $this->isi(),
            'aksi' => 'Tambahkan',
            'tautan' => route('pengaturan', ['tab' => 'perlengkapan']),
        ];
    }
}
