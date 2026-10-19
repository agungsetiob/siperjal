import exifr from 'exifr';
import { buatPeta, alamatDari, cariAlamat, jarakMeter } from './peta/index.js';

/** Pin bulat berwarna dengan ikon jenis perlengkapan (deskripsi ikon untuk adaptor peta). */
const pinJenis = (warna, ikon, besar = false, ukuran = null, kelas = '') => {
    const u = ukuran ?? (besar ? 40 : 34);
    return {
        html: `<span class="pin-jenis${besar ? ' besar' : ''} ${kelas}" style="background:${warna};width:${u}px;height:${u}px">${ikon}</span>`,
        ukuran: [u, u],
        jangkar: [u / 2, u / 2],
        popupAnchor: [0, -u / 2 - 4],
    };
};

/** Alamat dari koordinat — penyedia mengikuti Pengaturan → Peta. */
window.alamatDari = alamatDari;

/** Salin teks ke papan klip. `navigator.clipboard` hanya ada di HTTPS/localhost: di HTTP biasa pakai cadangan execCommand. */
window.salin = async (teks) => {
    try {
        if (navigator.clipboard?.writeText) return await navigator.clipboard.writeText(teks);
    } catch {
        // jatuh ke cadangan
    }
    const t = Object.assign(document.createElement('textarea'), { value: teks });
    t.setAttribute('readonly', '');
    t.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
    document.body.append(t);
    t.select();
    try {
        document.execCommand('copy');
    } finally {
        t.remove();
    }
};

/** Koordinat GPS dari foto (EXIF), null bila tidak ada. */
window.koordinatFoto = async (file) => {
    try {
        const g = await exifr.gps(file);
        return g?.latitude ? { lat: g.latitude, lng: g.longitude } : null;
    } catch {
        return null;
    }
};

/**
 * PWA: daftarkan service worker (public/sw.js) & tangkap ajakan pasang dari browser.
 * Tombol "Pasang aplikasi" ada di Profil ($store.pwa).
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
let ajakanPasang = null;
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    ajakanPasang = e;
    if (window.Alpine) Alpine.store('pwa').bisa = true;
});
window.addEventListener('appinstalled', () => {
    ajakanPasang = null;
    if (window.Alpine) Object.assign(Alpine.store('pwa'), { bisa: false, terpasang: true });
});

document.addEventListener('alpine:init', () => {
    /** Tombol ? di bar atas: panduan untuk layar yang sedang dibuka (data diambil tiap menu dibuka, jadi ikut tab aktif). Tekan ? untuk membuka. */
    Alpine.data('menuBantuan', (rute, url) => ({
        buka: false,
        memuat: false,
        data: null,
        async toggle() {
            this.buka = !this.buka;
            if (this.buka) await this.muat();
        },
        async muat() {
            this.memuat = !this.data;
            try {
                const q = new URLSearchParams(window.location.search);
                q.set('rute', rute ?? '');
                const r = await fetch(`${url}?${q}`, { headers: { Accept: 'application/json' } });
                if (r.ok) this.data = await r.json();
            } catch {
                // tanpa data: menu tetap menampilkan Semua panduan & WhatsApp
            } finally {
                this.memuat = false;
            }
        },
        tekan(e) {
            const el = document.activeElement;
            if (e.key !== '?' || e.ctrlKey || e.metaKey || e.altKey) return;
            if (/INPUT|TEXTAREA|SELECT/.test(el?.tagName ?? '') || el?.isContentEditable) return;
            e.preventDefault();
            this.toggle();
        },
    }));

    /** Panduan: cari di judul, ringkas, judul bagian, dan isi (semua kata harus cocok). Tekan / untuk fokus. */
    Alpine.data('cariPanduan', (indeks) => ({
        q: '',
        sheet: false,
        sorot: 0,
        get hasil() {
            const kata = this.q.trim().toLowerCase().split(/\s+/).filter(Boolean);
            if (this.q.trim().length < 2) return [];
            return indeks
                .map((a) => {
                    const j = a.judul.toLowerCase(), b = a.bagian.join(' ').toLowerCase(), r = a.ringkas.toLowerCase(), t = a.teks.toLowerCase();
                    let skor = 0;
                    for (const w of kata) {
                        if (j.includes(w)) skor += 10;
                        else if (b.includes(w)) skor += 5;
                        else if (r.includes(w)) skor += 3;
                        else if (t.includes(w)) skor += 1;
                        else return null;
                    }
                    return { ...a, skor };
                })
                .filter(Boolean)
                .sort((x, y) => y.skor - x.skor)
                .slice(0, 6);
        },
        geser(n) {
            this.sorot = Math.max(0, Math.min(this.hasil.length - 1, this.sorot + n));
        },
        buka() {
            const a = this.hasil[this.sorot];
            if (a) window.location.href = a.url;
        },
        tekan(e) {
            if (e.key !== '/' || /INPUT|TEXTAREA|SELECT/.test(document.activeElement?.tagName ?? '')) return;
            if (!this.$refs.cari || this.$refs.cari.offsetParent === null) return;
            e.preventDefault();
            this.$refs.cari.focus();
        },
    }));

    /** Panduan: sorot item daftar isi sesuai bagian yang sedang dibaca. */
    Alpine.data('daftarIsiPanduan', (ids) => ({
        aktif: ids[0] ?? null,
        init() {
            const hitung = () => {
                const el = ids.map((i) => document.getElementById(i)).filter(Boolean);
                let a = el[0]?.id ?? null;
                for (const e of el) if (e.getBoundingClientRect().top <= 140) a = e.id;
                if (window.innerHeight + window.scrollY >= document.body.scrollHeight - 4) a = el.at(-1)?.id ?? a;
                this.aktif = a;
            };
            window.addEventListener('scroll', hitung, { passive: true });
            hitung();
        },
    }));

    /** Status bersama peta ↔ daftar: id usulan yang terlihat di area peta. */
    /** Status sinyal untuk label unggah ("Menunggu sinyal…" saat offline; Livewire melanjutkan unggahan saat online). */
    Alpine.store('sinyal', { ada: navigator.onLine });

    Alpine.store('pwa', {
        bisa: !!ajakanPasang,
        terpasang: matchMedia('(display-mode: standalone)').matches || navigator.standalone === true,
        // iPhone/iPad tidak punya ajakan pasang: tampilkan petunjuk "Bagikan → Tambah ke Layar Utama"
        ios: /iphone|ipad|ipod/i.test(navigator.userAgent) && !navigator.standalone,
        async pasang() {
            if (!ajakanPasang) return;
            ajakanPasang.prompt();
            const { outcome } = await ajakanPasang.userChoice;
            ajakanPasang = null;
            this.bisa = false;
            if (outcome === 'accepted') this.terpasang = true;
        },
    });
    window.addEventListener('online', () => Alpine.store('sinyal').ada = true);
    window.addEventListener('offline', () => Alpine.store('sinyal').ada = false);

    Alpine.store('peta', {
        ids: null,
        saring: true,
        lihat(id) { return !this.saring || !this.ids || this.ids.includes(id) },
    });

    /** Peta usulan (Dishub): pin per umur usulan, kelompok, sorot, popover. */
    Alpine.data('petaUsulan', (titik, pusat, { penuh = false, pilih = null } = {}) => ({
        peta: null,
        penanda: {},
        satelit: false,
        pilih,
        async init() {
            this.peta = await buatPeta(this.$refs.peta, { pusat, zoom: 13, atribusi: true });
            const kelompok = this.peta.kelompok({ radius: 44, ukuran: 44, html: (n) => `<span class="pin-kelompok"><span>${n}</span></span>` });
            this.kelompok = kelompok;
            titik.forEach((t) => {
                const m = this.peta.penanda({
                    ...this.ikon(t),
                    lat: t.lat,
                    lng: t.lng,
                    dalam: kelompok,
                    tooltip: {
                        html: `<div class="flex w-[232px] flex-col gap-1.5 font-sans">
                        <div class="flex items-center justify-between font-caption text-xs"><span class="font-semibold text-accent">${t.nomor}</span><span class="${t.telat ? 'font-semibold text-warning' : 'text-fg-muted'}">${t.umur}</span></div>
                        <span class="text-sm font-semibold text-fg">${t.lokasi}</span>
                        <span class="font-caption text-xs text-fg-muted">${t.meta} · klik untuk buka</span></div>`,
                        kelas: 'tip-uj',
                        offset: [0, -18],
                    },
                    ...(penuh
                        ? { popup: { html: t.popup, kelas: 'popup-uj popup-foto', tutup: false, lebarMaks: 320, lebarMin: 300, autoGeser: { kiriAtas: [470, 90], kananBawah: [80, 60] } } }
                        : { klik: () => Livewire.dispatch('pilih-titik', { id: t.id }) }),
                });
                this.penanda[t.id] = { m, t };
            });

            const semua = titik.map((t) => [t.lat, t.lng]);
            if (titik.length) {
                this.peta.fitBounds(semua, { padding: penuh ? [80, 80] : [50, 50], paddingTopLeft: penuh && window.innerWidth >= 640 ? [480, 60] : [40, 110], maxZoom: 16 });
            }
            if (this.pilih) this.sorot(this.pilih, true);

            const perbarui = () => {
                if (!this.peta.siap()) return;
                Alpine.store('peta').ids = titik.filter((t) => this.peta.dalamBatas([t.lat, t.lng])).map((t) => t.id);
            };
            this.peta.pada('geser', perbarui);
            perbarui();

            window.addEventListener('pilih-berubah', (e) => this.sorot(e.detail.id, true));
            window.addEventListener('sorot-titik', (e) => this.hover(e.detail.id));
            window.addEventListener('tampilkan-semua', () => {
                Alpine.store('peta').saring = false;
                if (titik.length) this.peta.fitBounds(semua, { padding: [60, 60], paddingTopLeft: [480, 60], maxZoom: 16 });
            });
        },
        ikon(t, terpilih = false) {
            if (terpilih) {
                return {
                    html: `<span class="pin-terpilih"><span>${t.ikon}</span></span><span class="pin-label">${t.nomor} · dibuka</span>`,
                    ukuran: [52, 52],
                    jangkar: [26, 26],
                };
            }
            return pinJenis(t.telat || t.dekat ? '#8A5A00' : '#4556A9', t.ikon, false, 32);
        },
        sorot(id, geser = false) {
            if (!this.peta) return;
            Object.values(this.penanda).forEach(({ m, t }) => m.ikon(this.ikon(t, t.id === id)));
            const p = this.penanda[id];
            if (p) {
                p.m.z(1000);
                if (geser) this.kelompok.zoomKe(p.m, () => this.peta.panTo(p.m.posisi()));
            }
        },
        hover(id) {
            const p = this.penanda[id];
            if (!p || !this.peta) return;
            this.kelompok.zoomKe(p.m, () => p.m.bukaTooltip());
        },
        lokasiSaya() {
            navigator.geolocation?.getCurrentPosition((p) => this.peta.setView([p.coords.latitude, p.coords.longitude], 16));
        },
        gantiLapisan() {
            this.satelit = !this.satelit;
            this.peta.gantiLapisan(this.satelit);
            this.$refs.peta.classList.toggle('peta-redup', !this.satelit);
        },
    }));

    /** Rute pemasangan Dishub (HP: kartu geser · desktop: panel rute + popover). Titik awal = posisi petugas atau gudang. */
    Alpine.data('petaLapangan', (pusat, gudang) => ({
        titik: [],
        urut: [],
        aktif: 0,
        km: null,
        posisi: null,
        cari: '',
        satelit: false,
        desktop: window.matchMedia('(min-width: 1024px)').matches,
        async init() {
            this.titik = JSON.parse(this.$refs.awal.textContent);
            this.urutkan();
            this.peta = await buatPeta(this.$refs.peta, { pusat, zoom: 13, skala: true });
            this.lapis = this.peta.grup();
            this.gambar(true);
            navigator.geolocation?.watchPosition((p) => {
                const pertama = !this.posisi;
                this.posisi = { lat: p.coords.latitude, lng: p.coords.longitude, akurasi: p.coords.accuracy };
                if (pertama && !this.desktop) { this.urutkan(); this.gambar(true) } else { this.hitungJarak(); this.gambar() }
            }, () => {}, { enableHighAccuracy: true, maximumAge: 15000 });
        },
        get asal() {
            // posisi HP dipakai hanya bila masih di wilayah kerja (≤ 50 km dari gudang)
            const dekat = this.posisi && (!gudang?.lat || this.jarak(this.posisi, gudang) <= 50000);
            return (!this.desktop && dekat) ? this.posisi : (gudang?.lat ? gudang : this.posisi);
        },
        get estimasi() {
            const menit = Math.round((this.km ?? 0) / 25 * 60 + this.urut.length * 20);
            return menit >= 60 ? `${Math.floor(menit / 60)}j ${menit % 60}m` : `${menit}m`;
        },
        get legenda() {
            const ada = new Map();
            this.urut.forEach((t) => ada.set(t.jenis, t.ikon));
            return [...ada].map(([jenis, ikon]) => ({ jenis, ikon }));
        },
        get urlEkspor() {
            return `/usulan-rute.xlsx?ids=${this.urut.map((t) => t.id).join(',')}`;
        },
        get urlNavigasi() {
            if (!this.urut.length) return '#';
            const s = (t) => `${t.lat},${t.lng}`;
            const akhir = this.urut[this.urut.length - 1];
            const singgah = this.urut.slice(0, -1).slice(0, 9).map(s).join('|');
            return `https://www.google.com/maps/dir/?api=1&origin=${s(this.asal ?? this.urut[0])}&destination=${s(akhir)}` + (singgah ? `&waypoints=${encodeURIComponent(singgah)}` : '');
        },
        cocok(t) {
            const q = this.cari.trim().toLowerCase();
            return !q || `${t.lokasi} ${t.jenis} ${t.wilayah} ${t.nomor}`.toLowerCase().includes(q);
        },
        jarak(a, b) {
            return jarakMeter(a, b);
        },
        urutkan() {
            const sisa = [...this.titik];
            const hasil = [];
            let dari = this.asal ?? sisa[0];
            while (sisa.length) {
                sisa.sort((a, b) => this.jarak(dari, a) - this.jarak(dari, b));
                dari = sisa.shift();
                hasil.push(dari);
            }
            this.urut = hasil;
            this.aktif = Math.min(this.aktif, Math.max(0, hasil.length - 1));
            this.hitungJarak();
        },
        hitungJarak() {
            let total = 0, dari = this.asal;
            this.urut = this.urut.map((t) => {
                const langkah = dari ? this.jarak(dari, t) : null;
                total += langkah ?? 0;
                dari = t;
                return { ...t, langkah, jarak: this.posisi ? this.jarak(this.posisi, t) : null };
            });
            this.km = total ? Math.round(total / 1000) : null;
        },
        teksJarak(m) {
            return m < 1000 ? `${Math.max(10, Math.round(m / 10) * 10)} m` : `${(m / 1000).toFixed(1).replace('.', ',')} km`;
        },
        popup(t, n) {
            const foto = t.foto ? `<div class="relative mb-3 h-[140px] overflow-hidden rounded-sm"><img src="${t.foto}" class="size-full object-cover"><span class="absolute bottom-2 left-2 rounded-sm bg-fg/70 px-2 py-1 font-caption text-[11px] font-medium text-fg-inverse">Foto lokasi</span></div>` : '';
            const dari = n > 0 ? ` · +${this.teksJarak(t.langkah)} dari titik ${n}` : (t.langkah ? ` · +${this.teksJarak(t.langkah)} dari ${gudang?.nama ?? 'titik awal'}` : '');
            return `<div class="w-[288px] font-sans">${foto}
                <div class="flex flex-col gap-1 px-1">
                    <div class="flex items-center justify-between"><span class="font-caption text-[11px] font-semibold tracking-[0.4px] text-accent">TITIK ${n + 1} · ${t.nomor}</span>${t.tahun ? `<span class="rounded-sm bg-surface-muted px-1.5 py-0.5 font-caption text-[11px] font-semibold">TA ${t.tahun}</span>` : ''}</div>
                    <span class="judul text-[22px] leading-6 text-fg">${t.lokasi}</span>
                    <span class="text-xs text-fg-muted">${t.wilayah}${dari}</span>
                </div>
                <div class="mt-3 flex items-center gap-2.5 rounded-sm bg-surface-muted px-2.5 py-2">
                    <span class="grid size-8 shrink-0 place-items-center rounded-sm bg-accent-soft p-2 text-accent">${t.ikon}</span>
                    <span class="flex min-w-0 flex-1 flex-col"><span class="text-sm font-semibold text-fg">${t.jenis}</span><span class="truncate text-xs text-fg-muted">${t.spec ?? ''}</span></span>
                    <span class="judul text-[22px] leading-[22px] text-accent">${t.jumlah}</span><span class="font-caption text-[11px] font-semibold text-accent">${t.satuan}</span>
                </div>
                <div class="mt-3 flex gap-2">
                    <a href="https://www.google.com/maps/dir/?api=1&destination=${t.lat},${t.lng}" target="_blank" class="grid size-11 shrink-0 place-items-center rounded-sm text-fg outline outline-1 -outline-offset-1 outline-line"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg></a>
                    <button onclick="Livewire.dispatch('buka-titik', { id: ${t.id}, no: ${n + 1} })" class="flex h-11 flex-1 items-center justify-center gap-2 rounded-sm bg-accent text-[15px] font-semibold text-fg-inverse shadow-sm">Tandai Terpasang <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg></button>
                </div></div>`;
        },
        gambar(pas = false) {
            if (!this.peta) return;
            this.lapis.kosongkan();
            this.penanda = [];
            const jalur = [this.asal, ...this.urut].filter(Boolean).map((t) => [t.lat, t.lng]);
            if (jalur.length > 1) this.peta.garis(jalur, { warna: '#4556A9', tebal: this.desktop ? 4 : 3, opacity: 0.75, putus: this.desktop ? null : '6 8' }, this.lapis);
            if (gudang?.lat && (this.desktop || !this.posisi)) {
                this.peta.penanda({
                    lat: gudang.lat, lng: gudang.lng, interaktif: false, dalam: this.lapis,
                    html: `<span class="pin-gudang"><span>⌂</span><em>${gudang.nama} · ${gudang.jam}</em></span>`, ukuran: [32, 32], jangkar: [16, 16],
                });
            }
            this.urut.forEach((t, n) => {
                const pilih = n === this.aktif;
                this.penanda[n] = this.peta.penanda({
                    lat: t.lat, lng: t.lng, dalam: this.lapis,
                    z: pilih ? 1000 : 0,
                    html: `<span class="pin-titik${pilih ? ' dipilih' : ''}"><span class="isi">${t.ikon}</span><span class="nomor">${n + 1}</span></span>`,
                    ukuran: pilih ? [56, 56] : [48, 48],
                    jangkar: pilih ? [28, 28] : [24, 24],
                    ...(this.desktop
                        ? {
                            tooltip: { html: `${n + 1} · ${t.lokasi}`, kelas: 'tip-titik', offset: [0, -22] },
                            popup: { html: this.popup(t, n), kelas: 'popup-uj', tutup: true, lebarMin: 288, lebarMaks: 312, offset: [0, -14] },
                            klik: () => { this.aktif = n; this.sorotSaja() },
                        }
                        : { klik: () => this.pilih(n) }),
                });
            });
            if (this.posisi) {
                this.peta.penanda({
                    lat: this.posisi.lat, lng: this.posisi.lng, interaktif: false, dalam: this.lapis,
                    html: '<span class="pin-saya"><span></span><em>Lokasi saya</em></span>', ukuran: [40, 40], jangkar: [20, 20],
                });
            }
            if (pas && jalur.length) {
                this.peta.fitBounds(jalur, this.desktop
                    ? { paddingTopLeft: [480, 60], paddingBottomRight: [260, 80], maxZoom: 16 }
                    : { padding: [60, 60], paddingBottomRight: [40, 320], maxZoom: 16 });
            }
        },
        sorotSaja() {
            this.penanda.forEach((m, n) => m.z(n === this.aktif ? 1000 : 0));
        },
        sorot(n) {
            this.penanda[n]?.bukaTooltip();
        },
        geserKartu() {
            const el = this.$refs.kartu;
            const lebar = el.querySelector(':scope > div')?.offsetWidth ?? el.clientWidth;
            const n = Math.round(el.scrollLeft / (lebar + 8));
            if (n !== this.aktif && this.urut[n]) {
                this.aktif = n;
                this.gambar();
                this.peta?.panTo([this.urut[n].lat, this.urut[n].lng]);
            }
        },
        pilih(n, bukaPopup = false) {
            this.aktif = n;
            if (this.desktop) {
                this.gambar();
                const m = this.penanda[n];
                if (m) { this.peta.setView(m.posisi(), Math.max(this.peta.getZoom(), 15)); if (bukaPopup) m.bukaPopup() }
                return;
            }
            const kartu = this.$refs.kartu?.querySelectorAll(':scope > div')[n];
            kartu?.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            this.gambar();
        },
        susunUlang() {
            this.urutkan();
            this.gambar(true);
            this.$dispatch('toast', { pesan: this.posisi && !this.desktop ? 'Rute disusun ulang dari lokasi Anda' : 'Rute disusun ulang dari titik awal' });
        },
        gantiLapisan() {
            this.satelit = !this.satelit;
            this.peta.gantiLapisan(this.satelit);
            this.$refs.peta.classList.toggle('peta-redup', !this.satelit);
        },
        kePosisi() {
            if (this.posisi) this.peta.setView([this.posisi.lat, this.posisi.lng], 16);
            else navigator.geolocation?.getCurrentPosition((p) => this.peta.setView([p.coords.latitude, p.coords.longitude], 16));
        },
        perbarui(titik) {
            this.titik = titik;
            this.urutkan();
            this.gambar();
        },
    }));

    /** Tugas Saya petugas lapangan (laporan kerusakan): pin bernomor, kartu geser, jarak dari posisi HP. */
    Alpine.data('petaTugas', (pusat) => ({
        peta: null,
        lapis: null,
        titik: [],
        aktif: 0,
        gps: null,
        jarak: {},
        km: null,
        async init() {
            this.titik = JSON.parse(this.$refs.awal.textContent);
            this.peta = await buatPeta(this.$refs.peta, { pusat, zoom: 13 });
            this.lapis = this.peta.grup();
            this.gambar(true);
            navigator.geolocation?.getCurrentPosition((p) => {
                this.gps = { lat: p.coords.latitude, lng: p.coords.longitude };
                this.peta.penanda({
                    lat: this.gps.lat, lng: this.gps.lng, interaktif: false,
                    html: '<span style="display:block;width:20px;height:20px;border-radius:9999px;background:#1A73E8;border:3px solid #fff;box-shadow:0 1px 4px #0005"></span>',
                    ukuran: [20, 20], jangkar: [10, 10],
                });
                this.hitung();
            }, () => {}, { enableHighAccuracy: true, timeout: 10000 });
        },
        perbarui(titik) {
            this.titik = titik;
            this.aktif = 0;
            this.gambar(true);
            this.hitung();
        },
        gambar(muat = false) {
            if (!this.peta) return;
            this.lapis.kosongkan();
            this.titik.forEach((t, i) => {
                const nomor = `<b style="font:700 14px/1 sans-serif;color:#fff">${t.no}</b>`;
                this.peta.penanda({
                    ...pinJenis(i === this.aktif ? '#000100' : '#4556A9', nomor, i === this.aktif),
                    lat: t.lat, lng: t.lng, dalam: this.lapis,
                    z: i === this.aktif ? 1000 : 0,
                    klik: () => this.pilih(i, true),
                });
            });
            if (muat && this.titik.length) this.peta.fitBounds(this.titik.map((t) => [t.lat, t.lng]), { paddingTopLeft: [40, 80], paddingBottomRight: [40, 280], maxZoom: 16 });
        },
        pilih(i, gulir = false) {
            if (!this.titik[i]) return;
            this.aktif = i;
            this.gambar();
            this.peta?.panTo([this.titik[i].lat, this.titik[i].lng]);
            if (gulir) this.$refs.kartu?.children[i]?.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
        },
        geserKartu() {
            const k = this.$refs.kartu;
            if (!k?.children.length) return;
            const i = Math.round(k.scrollLeft / (k.children[0].offsetWidth + 8));
            if (i !== this.aktif) this.pilih(i);
        },
        kePosisi() {
            if (this.gps) this.peta.setView([this.gps.lat, this.gps.lng], 16);
        },
        hitung() {
            if (!this.gps) return;
            const m = (a, b) => Math.round(jarakMeter(a, b));
            this.jarak = Object.fromEntries(this.titik.map((t) => [t.id, m(this.gps, t)]));
            const total = this.titik.reduce((s, t, i) => s + m(i ? this.titik[i - 1] : this.gps, t), 0);
            this.km = this.titik.length ? (total / 1000).toFixed(total < 10000 ? 1 : 0).replace('.', ',') : null;
        },
        teksJarak(m) {
            return m < 1000 ? `${m} m` : `${(m / 1000).toFixed(1).replace('.', ',')} km`;
        },
    }));

    /** Peta kecil di detail usulan: pin, radius duplikat, dan usulan berdekatan. */
    Alpine.data('petaTitik', (titik, sekitar, radius, ikon, ikonDuplikat) => ({
        async init() {
            const p = await buatPeta(this.$refs.peta, { pusat: [titik.lat, titik.lng], zoom: 17, scrollZoom: false });
            p.lingkaran([titik.lat, titik.lng], radius, { warna: '#4556A9' });
            sekitar.forEach((b) => p.penanda({ ...pinJenis('#8A5A00', ikonDuplikat, false, 22), lat: b.lat, lng: b.lng, interaktif: false }));
            p.penanda({ ...pinJenis('#4556A9', ikon, false, 26), lat: titik.lat, lng: titik.lng, interaktif: false });
        },
    }));

    /** Peta kecil statis dengan satu pin (halaman lacak). */
    Alpine.data('petaMini', (lat, lng, ikon) => ({
        async init() {
            const p = await buatPeta(this.$el, { pusat: [lat, lng], zoom: 16, statis: true });
            p.penanda({ ...pinJenis('#4556A9', ikon, true), lat, lng, interaktif: false });
        },
    }));

    /** Peta publik perlengkapan terpasang (beranda). */
    Alpine.data('petaTerpasang', ({ jenis, titik }, pusat) => ({
        async init() {
            const lebar = window.innerWidth >= 1024;
            const peta = await buatPeta(this.$refs.peta, { pusat, zoom: 14, scrollZoom: false, atribusi: true, zoomKontrol: true });
            const pin = titik.map((t, i) => {
                const j = jenis[t.jenis] ?? { warna: '#4556A9', ikon: '' };
                return peta.penanda({
                    ...pinJenis(j.warna, j.ikon, i === 0, null, t.rusak ? 'rusak' : ''),
                    lat: t.lat, lng: t.lng,
                    popup: { html: t.popup, kelas: 'popup-uj', tutup: false, lebarMaks: 260, autoPan: false },
                });
            });
            if (titik.length) {
                // Geser titik ke sisi kanan layar agar tidak tertutup teks hero.
                peta.fitBounds(titik.map((t) => [t.lat, t.lng]), { paddingTopLeft: [lebar ? 780 : 40, 170], paddingBottomRight: [lebar ? 320 : 40, 250], maxZoom: 16 });
                pin[0].bukaPopup();
            } else if (lebar) {
                peta.panBy([-380, 0], false);
            }
        },
    }));

    /**
     * Peta untuk memilih satu titik (pin bisa digeser / tap).
     * `titik` (opsional, form lapor kerusakan): pin perlengkapan terpasang; ketuk → event `pilih-titik`.
     */
    Alpine.data('petaPilih', ({ lat, lng, pusat, titik = [], aktif = null }) => ({
        peta: null,
        pin: null,
        lapis: null,
        aktif,
        cari: '',
        hasil: [],
        cariBuka: false,
        async init() {
            const awal = lat ? [lat, lng] : pusat;
            this.peta = await buatPeta(this.$refs.peta, { pusat: awal, zoom: lat ? 17 : 13, atribusi: true });
            if (titik.length) {
                this.lapis = this.peta.grup();
                this.gambarTitik();
            }
            if (lat) this.taruh(lat, lng, false);
            if (this.aktif) this.pin?.opacity(0);
            this.peta.pada('klik', (e) => this.taruh(e.lat, e.lng));
        },
        gambarTitik() {
            this.lapis.kosongkan();
            titik.forEach((t) => {
                const on = t.id === this.aktif;
                this.peta.penanda({
                    ...pinJenis(on ? '#000100' : t.warna, t.ikon, on, on ? 36 : 28),
                    lat: t.lat, lng: t.lng, dalam: this.lapis,
                    z: on ? 1000 : 0,
                    klik: () => { this.sorot(t.id); this.$dispatch('pilih-titik', { id: t.id }) },
                });
            });
        },
        sorot(id) {
            this.aktif = id;
            if (this.lapis) this.gambarTitik();
            this.pin?.opacity(id ? 0 : 1); // tertaut: pin perlengkapan yang disorot menggantikan pin lokasi
        },
        taruh(la, ln, kirim = true, geser = false) {
            if (!this.peta) return;
            if (!this.pin) {
                this.pin = this.peta.penanda({
                    lat: la, lng: ln, geser: true,
                    html: `<span class="pin-lokasi"><span class="akurasi"></span><svg viewBox="0 0 24 24" fill="#4556A9" stroke="#fff" stroke-width="1.5"><path d="M12 22s8-6.5 8-13a8 8 0 1 0-16 0c0 6.5 8 13 8 13Z"/><circle cx="12" cy="9" r="3" fill="#fff" stroke="none"/></svg></span>`,
                    ukuran: [36, 36],
                    jangkar: [18, 34],
                    geserSelesai: (a, b) => this.kirim(a, b),
                });
            } else {
                this.pin.setPosisi(la, ln);
            }
            if (geser || !kirim) this.peta.setView([la, ln], 17);
            if (kirim) this.kirim(la, ln);
        },
        async kirim(la, ln) {
            this.$wire.aturLokasi(la, ln, await alamatDari(la, ln));
        },
        lokasiSaya() {
            navigator.geolocation?.getCurrentPosition(
                (p) => this.taruh(p.coords.latitude, p.coords.longitude, true, true),
                () => alert('Lokasi HP tidak bisa dibaca. Nyalakan GPS atau tandai di peta.'),
                { enableHighAccuracy: true, timeout: 10000 },
            );
        },
        async cariAlamat() {
            if (this.cari.length < 3) return;
            this.hasil = await cariAlamat(this.cari);
        },
        pilihHasil(h) {
            this.hasil = [];
            this.cari = h.display_name.split(',')[0];
            this.taruh(+h.lat, +h.lon, true, true);
        },
    }));
});

/**
 * Koneksi putus / request gagal.
 * Offline: aksi yang gagal diantre lalu dikirim ulang otomatis saat online (isian wire:model ikut
 * terkirim karena belum tercatat di server). Online tapi gagal: toast "Coba lagi".
 */
const antreanOffline = [];
const kirimUlang = (daftar) => daftar.forEach(({ id, calls }) => {
    const wire = window.Livewire.find(id); // Livewire.find() mengembalikan $wire komponen
    if (!wire) return;
    if (!calls.length) return wire.$commit();
    calls.forEach((k) => wire[k.method]?.(...k.params));
});
const kabar = (nama, detail = {}) => window.dispatchEvent(new CustomEvent(nama, { detail }));

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ component, succeed }) => succeed(() => kabar('lw-berhasil', { nama: component.name })));

    // Jaringan putus hanya memicu `fail` di hook request (bukan commit): baca aksi dari payload.
    window.Livewire.hook('request', ({ payload, fail }) => {
        fail(() => {
            let items = [];
            try {
                items = (JSON.parse(payload).components ?? []).map((c) => {
                    const memo = JSON.parse(c.snapshot).memo;
                    return { id: memo.id, nama: memo.name, calls: (c.calls ?? []).map(({ method, params }) => ({ method, params })) };
                });
            } catch {
                return;
            }
            if (!navigator.onLine) {
                antreanOffline.push(...items);
                kabar('antre-offline', { jumlah: antreanOffline.length });
                return;
            }
            const ulang = () => kirimUlang(items);
            const pilih = items.some((k) => k.calls.some((c) => ['pilihUsulan', 'pindah'].includes(c.method)));
            items.forEach((k) => kabar('lw-gagal', { nama: k.nama, pilih, ulang }));
            if (!pilih) kabar('toast', { gagal: true, judul: 'Gagal disimpan', pesan: 'Isian Anda masih ada. Periksa koneksi, lalu coba lagi.', aksi: 'Coba lagi', ulang });
        });
    });
});
window.addEventListener('online', () => {
    const daftar = antreanOffline.splice(0);
    kirimUlang(daftar);
    kabar('antre-terkirim', { jumlah: daftar.length });
});
