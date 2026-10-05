<?php

namespace App\Enums;

enum Kriteria: string
{
    case Kecelakaan = 'kecelakaan';
    case JarakPandang = 'jarak_pandang';
    case Sekolah = 'sekolah';
    case Padat = 'padat';
    case JalanUtama = 'jalan_utama';

    /** Label awam untuk pengusul. */
    public function labelAwam(): string
    {
        return match ($this) {
            self::Kecelakaan => 'Sering terjadi kecelakaan',
            self::JarakPandang => 'Pandangan terhalang / tikungan tajam',
            self::Sekolah => 'Dekat sekolah / ramai anak-anak',
            self::Padat => 'Lalu lintas ramai',
            self::JalanUtama => 'Jalan utama',
        };
    }

    /** Label resmi untuk Dishub. */
    public function label(): string
    {
        return match ($this) {
            self::Kecelakaan => 'Rawan kecelakaan',
            self::JarakPandang => 'Jarak pandang terbatas',
            self::Sekolah => 'Aktivitas anak / sekolah',
            self::Padat => 'Volume lalu lintas padat',
            self::JalanUtama => 'Jalan poros primer/sekunder',
        };
    }

    public function ikon(): string
    {
        return match ($this) {
            self::Kecelakaan => 'siren',
            self::JarakPandang => 'eye-off',
            self::Sekolah => 'school',
            self::Padat => 'car',
            self::JalanUtama => 'route',
        };
    }
}
