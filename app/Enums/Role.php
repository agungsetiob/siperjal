<?php

namespace App\Enums;

enum Role: string
{
    case Pengusul = 'pengusul';
    case Dishub = 'dishub';
    case Bappeda = 'bappeda';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Pengusul => 'Pengusul',
            self::Dishub => 'Dishub',
            self::Bappeda => 'Bappeda',
            self::Admin => 'Admin',
        };
    }
}
