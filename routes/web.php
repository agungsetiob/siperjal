<?php

use App\Http\Controllers\BerkasController;
use App\Http\Controllers\LaporanExportController;
use App\Http\Controllers\PanduanController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Publik
Route::livewire('/', 'pages::beranda')->name('beranda');
Route::livewire('/lacak/{nomor}', 'pages::lacak')->name('lacak');
// Lapor kerusakan tanpa akun (dari popup peta beranda / halaman lacak) & status laporan
Route::livewire('/lapor/status/{nomor}', 'pages::pemeliharaan.publik')->name('lapor.status');
Route::livewire('/lapor/aset/{item}', 'pages::pemeliharaan.publik')->whereNumber('item')->name('lapor.aset');
// tautan lama berbasis id item usulan (QR/pesan yang sudah tersebar) → perlengkapan terpasangnya
Route::get('/lapor/{item}', function (int $item) {
    $aset = \App\Models\PerlengkapanTerpasang::where('usulan_item_id', $item)->firstOrFail();

    return redirect()->route('lapor.aset', $aset->id, 301);
})->whereNumber('item')->name('lapor.item');
Route::livewire('/masuk', 'pages::masuk')->middleware('guest')->name('masuk');
Route::redirect('/login', '/masuk')->name('login');

// Panduan: ditulis di Markdown (folder panduan/), tanpa akun
Route::get('/panduan', [PanduanController::class, 'index'])->name('panduan');
Route::get('/panduan/gambar/{nama}', [PanduanController::class, 'gambar'])->name('panduan.gambar');
Route::get('/panduan/{grup}/{slug}', [PanduanController::class, 'artikel'])->name('panduan.artikel');

Route::post('/keluar', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/');
})->name('keluar');

// Aplikasi
Route::middleware(['auth', 'aktif'])->group(function () {
    Route::livewire('/usulan/baru', 'pages::usulan.form')->name('usulan.baru');
    Route::livewire('/usulan/{usulan}/ubah', 'pages::usulan.form')->name('usulan.ubah');
    Route::livewire('/usulan/{usulan?}', 'pages::usulan.index')->name('usulan');
    Route::get('/usulan/{usulan}/proposal', [BerkasController::class, 'proposal'])->name('usulan.proposal');
    Route::get('/usulan/{usulan}/pratinjau', [BerkasController::class, 'pratinjau'])->name('usulan.pratinjau');

    // Pemeliharaan: laporan kerusakan perlengkapan terpasang
    Route::livewire('/lapor', 'pages::pemeliharaan.lapor')->middleware('role:pengusul')->name('lapor');
    Route::livewire('/pemeliharaan/{laporan?}', 'pages::pemeliharaan.index')->middleware('role:pengusul,dishub,admin')->name('pemeliharaan');

    // Perlengkapan terpasang (termasuk yang terpasang sebelum SIPERJAL): Dishub mengelola, admin melihat
    Route::livewire('/terpasang', 'pages::terpasang.index')->middleware('role:dishub,admin')->name('terpasang');
    Route::get('/terpasang/template.xlsx', \App\Http\Controllers\TemplateTerpasangController::class)->middleware('role:dishub')->name('terpasang.template');

    Route::get('/panduan/menu', [PanduanController::class, 'menu'])->name('panduan.menu');
    Route::livewire('/notifikasi', 'pages::notifikasi')->name('notifikasi');
    Route::livewire('/profil', 'pages::profil')->name('profil');
    Route::get('/usulan-rute.xlsx', \App\Http\Controllers\RuteController::class)->middleware('role:dishub,admin')->name('usulan.rute');
    Route::livewire('/laporan', 'pages::laporan')->middleware('role:dishub,bappeda,admin')->name('laporan');
    Route::livewire('/pengaturan', 'pages::pengaturan')->middleware('role:admin')->name('pengaturan');
    Route::get('/laporan/export/{format}', LaporanExportController::class)->middleware('role:dishub,bappeda,admin')->name('laporan.export');
});
