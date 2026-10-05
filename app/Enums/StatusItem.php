<?php

namespace App\Enums;

enum StatusItem: string
{
    case Menunggu = 'menunggu';
    case Lolos = 'lolos';
    case DitolakDishub = 'ditolak_dishub';
    case Disetujui = 'disetujui';
    case Ditunda = 'ditunda';
    case DitolakBappeda = 'ditolak_bappeda';
    case Terpasang = 'terpasang';

    public function label(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu',
            self::Lolos => 'Lolos Dishub',
            self::DitolakDishub, self::DitolakBappeda => 'Ditolak',
            self::Disetujui => 'Disetujui',
            self::Ditunda => 'Ditunda',
            self::Terpasang => 'Terpasang',
        };
    }

    /** Nada warna badge: netral, proses, perhatian, bahaya, sukses */
    public function tone(): string
    {
        return match ($this) {
            self::Menunggu, self::Lolos => 'netral',
            self::Disetujui => 'proses',
            self::DitolakDishub, self::DitolakBappeda => 'bahaya',
            self::Terpasang => 'sukses',
            self::Ditunda => 'perhatian',
        };
    }
}
