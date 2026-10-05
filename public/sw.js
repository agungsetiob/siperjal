/*
 * Service worker SIPERJAL.
 * - Aset statis (/build, /icons, livewire.js) disimpan: buka aplikasi lebih cepat & tetap tampil saat sinyal lemah.
 * - Halaman (HTML) TIDAK disimpan — isinya data milik akun yang masuk; saat offline tampil /offline.html.
 * - Permintaan selain GET (aksi Livewire) dibiarkan: antrean offline di resources/js/app.js yang menanganinya.
 * Naikkan VERSI bila mengubah berkas ini atau offline.html.
 */
const VERSI = 'siperjal-v2';
const MAKS = 80; // batas isi cache: aset build lama tersingkir otomatis
const AWAL = ['/offline.html', '/icons/icon-192.png', '/icons/icon.svg', '/manifest.webmanifest'];

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(VERSI).then((c) => c.addAll(AWAL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys()
            .then((kunci) => Promise.all(kunci.filter((k) => k !== VERSI).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

/** Buang entri tertua (urutan simpan) bila melebihi MAKS; berkas awal tidak ikut dibuang. */
const rapikan = async (cache) => {
    const kunci = (await cache.keys()).filter((r) => !AWAL.includes(new URL(r.url).pathname));
    await Promise.all(kunci.slice(0, Math.max(0, kunci.length - (MAKS - AWAL.length))).map((r) => cache.delete(r)));
};

const asetStatis = (url) => url.origin === self.location.origin
    && (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || /^\/livewire[^/]*\/livewire(\.min)?\.js$/.test(url.pathname));

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);

    // Halaman: selalu dari jaringan; tanpa sinyal → halaman offline
    if (req.mode === 'navigate') {
        e.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
        return;
    }

    // Aset bernama-hash: ambil dari cache, simpan bila belum ada
    if (asetStatis(url)) {
        e.respondWith(
            caches.match(req).then((ada) => ada || fetch(req).then((res) => {
                if (res.ok) {
                    const salinan = res.clone();
                    caches.open(VERSI).then((c) => c.put(req, salinan).then(() => rapikan(c)));
                }
                return res;
            })),
        );
    }
});
