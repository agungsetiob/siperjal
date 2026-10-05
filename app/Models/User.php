<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'aktif' => 'boolean',
            'notif_email' => 'boolean',
            'notif_wa' => 'boolean',
        ];
    }

    public function instansi()
    {
        return $this->belongsTo(Instansi::class);
    }

    /** Laporan kerusakan yang ditugaskan ke petugas ini. */
    public function tugasLaporan()
    {
        return $this->hasMany(LaporanKerusakan::class, 'petugas_id');
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isPengusul(): bool
    {
        return $this->role === Role::Pengusul;
    }

    public function inisial(): string
    {
        return str($this->name)->explode(' ')->filter()->take(2)->map(fn ($k) => mb_strtoupper(mb_substr($k, 0, 1)))->implode('');
    }

    public function fotoUrl(): ?string
    {
        return $this->foto_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->foto_path) : null;
    }

    /** Baris kedua di bar atas, mis. "Dishub · Pemeriksa" atau "Masyarakat". */
    public function keteranganPeran(): string
    {
        if ($this->isPengusul()) {
            return $this->instansi?->nama ?? 'Masyarakat';
        }

        return collect([$this->role->label(), $this->jabatan])->filter()->implode(' · ');
    }

    public function jenisPengusul(): string
    {
        return $this->instansi_id ? 'Instansi' : 'Masyarakat';
    }
}
