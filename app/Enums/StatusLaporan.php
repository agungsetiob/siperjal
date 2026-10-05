<?php

namespace App\Enums;

enum StatusLaporan: string
{
    case Baru = 'baru';
    case Ditugaskan = 'ditugaskan';
    case MenungguPerbaikan = 'menunggu_perbaikan';
    case Selesai = 'selesai';
    case Ditolak = 'ditolak';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Baru => 'Baru',
            self::Ditugaskan => 'Ditugaskan',
            self::MenungguPerbaikan => 'Menunggu perbaikan',
            self::Selesai => 'Selesai diperbaiki',
            self::Ditolak => 'Ditolak',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Baru, self::Ditugaskan => 'proses',
            self::MenungguPerbaikan => 'perhatian',
            self::Selesai => 'sukses',
            self::Ditolak => 'bahaya',
            self::Dibatalkan => 'netral',
        };
    }

    public function aktif(): bool
    {
        return in_array($this, [self::Baru, self::Ditugaskan, self::MenungguPerbaikan], true);
    }

    /** Langkah aktif di stepper 4 langkah: 1 Dilaporkan, 2 Dicek petugas, 3 Menunggu perbaikan, 4 Selesai diperbaiki (5 = semua beres). */
    public function tahap(): int
    {
        return match ($this) {
            self::Baru, self::Ditugaskan, self::Ditolak, self::Dibatalkan => 2,
            self::MenungguPerbaikan => 3,
            self::Selesai => 5,
        };
    }
}
