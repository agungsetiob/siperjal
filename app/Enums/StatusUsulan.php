<?php

namespace App\Enums;

enum StatusUsulan: string
{
    case Draft = 'draft';
    case Diperiksa = 'diperiksa';
    case Revisi = 'revisi';
    case MenungguBappeda = 'menunggu_bappeda';
    case Disetujui = 'disetujui';
    case Terpasang = 'terpasang';
    case Ditunda = 'ditunda';
    case Ditolak = 'ditolak';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Diperiksa => 'Diperiksa Dishub',
            self::Revisi => 'Perlu Revisi',
            self::MenungguBappeda => 'Menunggu Bappeda',
            self::Disetujui => 'Disetujui',
            self::Terpasang => 'Terpasang',
            self::Ditunda => 'Ditunda',
            self::Ditolak => 'Ditolak',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft, self::Dibatalkan => 'netral',
            self::Diperiksa, self::MenungguBappeda => 'proses',
            self::Revisi, self::Ditunda => 'perhatian',
            self::Ditolak => 'bahaya',
            self::Disetujui, self::Terpasang => 'sukses',
        };
    }

    /** Kalimat ramah untuk pengusul & halaman lacak. */
    public function penjelasan(): string
    {
        return match ($this) {
            self::Draft => 'Usulan belum dikirim.',
            self::Diperiksa => 'Usulan sedang diperiksa Dishub (biasanya ± 7 hari).',
            self::Revisi => 'Dishub meminta beberapa bagian diperbaiki.',
            self::MenungguBappeda => 'Lolos pemeriksaan Dishub, menunggu keputusan Bappeda.',
            self::Disetujui => 'Disetujui dan dijadwalkan untuk dipasang.',
            self::Terpasang => 'Perlengkapan sudah terpasang. Terima kasih atas usulannya!',
            self::Ditunda => 'Ditunda ke tahun anggaran berikutnya.',
            self::Ditolak => 'Usulan tidak dapat disetujui.',
            self::Dibatalkan => 'Usulan dibatalkan oleh pengusul.',
        };
    }

    /** Tahap aktif di stepper (1 Diajukan, 2 Dishub, 3 Bappeda, 4 Terpasang). */
    public function tahap(): int
    {
        return match ($this) {
            self::Draft, self::Dibatalkan => 0,
            self::Diperiksa, self::Revisi => 2,
            self::MenungguBappeda, self::Ditunda => 3,
            self::Disetujui => 4,
            self::Terpasang => 5,
            self::Ditolak => 2,
        };
    }
}
