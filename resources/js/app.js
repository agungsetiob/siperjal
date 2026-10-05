import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import exifr from 'exifr';

const WARNA = { netral: '#9ca3af', proses: '#4556a9', perhatian: '#d99a00', bahaya: '#c62828', sukses: '#0b7a52' };

const ubin = () => L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap',
});

/** Pin bulat berwarna dengan ikon jenis perlengkapan. */
const pinJenis = (warna, ikon, besar = false, ukuran = null, kelas = '') => {
    const u = ukuran ?? (besar ? 40 : 34);
    return L.divIcon({
        className: '',
        html: `<span class="pin-jenis${besar ? ' besar' : ''} ${kelas}" style="background:${warna};width:${u}px;height:${u}px">${ikon}</span>`,
        iconSize: [u, u],
        iconAnchor: [u / 2, u / 2],
        popupAnchor: [0, -u / 2 - 4],
    });
};

const penanda = (tone, aktif = false) => L.divIcon({
    className: '',
    html: `<span style="display:block;width:${aktif ? 22 : 16}px;height:${aktif ? 22 : 16}px;border-radius:9999px;background:${WARNA[tone] ?? WARNA.proses};border:3px solid #fff;box-shadow:0 1px 4px #0005"></span>`,
    iconSize: aktif ? [22, 22] : [16, 16],
});

/** Alamat dari koordinat (OpenStreetMap Nominatim). */
async function alamatDari(lat, lng) {
    try {
        const r = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&accept-language=id&lat=${lat}&lon=${lng}`);
        const a = (await r.json()).address ?? {};
        return {
            jalan: [a.road, a.neighbourhood, a.hamlet].filter(Boolean).join(', '),
            desa: a.village ?? a.suburb ?? a.quarter ?? '',
            kecamatan: (a.city_district ?? a.municipality ?? a.town ?? '').replace(/^Kecamatan\s+/i, ''),
        };
    } catch {
        return { jalan: '', desa: '', kecamatan: '' };
    }
}
window.alamatDari = alamatDari;

/** Koordinat GPS dari foto (EXIF), null bila tidak ada. */
window.koordinatFoto = async (file) => {
    try {
        const g = await exifr.gps(file);
        return g?.latitude ? { lat: g.latitude, lng: g.longitude } : null;
    } catch {
        return null;
    }
};

const satelit = () => L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxZoom: 19,
    attribution: 'Citra &copy; Esri',
});

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
        lapisan: null,
        pilih,
        init() {
            this.peta = L.map(this.$refs.peta, { zoomControl: false, attributionControl: true }).setView(pusat, 13);
            this.lapisan = ubin().addTo(this.peta);
            const kelompok = L.markerClusterGroup({
                showCoverageOnHover: false,
                maxClusterRadius: 44,
                iconCreateFunction: (c) => L.divIcon({
                    className: '',
                    html: `<span class="pin-kelompok"><span>${c.getChildCount()}</span></span>`,
                    iconSize: [44, 44],
                }),
            });
            titik.forEach((t) => {
                const m = L.marker([t.lat, t.lng], { icon: this.ikon(t) });
                m.bindTooltip(`<div class="flex w-[232px] flex-col gap-1.5 font-sans">
                        <div class="flex items-center justify-between font-caption text-xs"><span class="font-semibold text-accent">${t.nomor}</span><span class="${t.telat ? 'font-semibold text-warning' : 'text-fg-muted'}">${t.umur}</span></div>
                        <span class="text-sm font-semibold text-fg">${t.lokasi}</span>
                        <span class="font-caption text-xs text-fg-muted">${t.meta} · klik untuk buka</span></div>`,
                    { className: 'tip-uj', direction: 'top', offset: [0, -18] });
                if (penuh) {
                    m.bindPopup(t.popup, { className: 'popup-uj popup-foto', closeButton: false, maxWidth: 320, minWidth: 300, autoPanPaddingTopLeft: L.point(470, 90), autoPanPaddingBottomRight: L.point(80, 60) });
                } else {
                    m.on('click', () => Livewire.dispatch('pilih-titik', { id: t.id }));
                }
                this.penanda[t.id] = { m, t };
                kelompok.addLayer(m);
            });
            this.peta.addLayer(kelompok);
            this.kelompok = kelompok;

            if (titik.length) {
                this.peta.fitBounds(kelompok.getBounds(), { padding: penuh ? [80, 80] : [50, 50], paddingTopLeft: penuh && window.innerWidth >= 640 ? [480, 60] : [40, 110], maxZoom: 16 });
            }
            if (this.pilih) this.sorot(this.pilih, true);

            const perbarui = () => {
                const b = this.peta.getBounds();
                Alpine.store('peta').ids = titik.filter((t) => b.contains([t.lat, t.lng])).map((t) => t.id);
            };
            this.peta.on('moveend', perbarui);
            perbarui();

            window.addEventListener('pilih-berubah', (e) => this.sorot(e.detail.id, true));
            window.addEventListener('sorot-titik', (e) => this.hover(e.detail.id));
            window.addEventListener('tampilkan-semua', () => {
                Alpine.store('peta').saring = false;
                if (titik.length) this.peta.fitBounds(kelompok.getBounds(), { padding: [60, 60], paddingTopLeft: [480, 60], maxZoom: 16 });
            });
            new ResizeObserver(() => this.peta.invalidateSize()).observe(this.$refs.peta);
        },
        ikon(t, terpilih = false) {
            if (terpilih) {
                return L.divIcon({
                    className: '',
                    html: `<span class="pin-terpilih"><span>${t.ikon}</span></span><span class="pin-label">${t.nomor} · dibuka</span>`,
                    iconSize: [52, 52],
                    iconAnchor: [26, 26],
                });
            }
            return pinJenis(t.telat || t.dekat ? '#8A5A00' : '#4556A9', t.ikon, false, 32);
        },
        sorot(id, geser = false) {
            Object.values(this.penanda).forEach(({ m, t }) => m.setIcon(this.ikon(t, t.id === id)));
            const p = this.penanda[id];
            if (p) {
                p.m.setZIndexOffset(1000);
                if (geser) this.kelompok.zoomToShowLayer(p.m, () => this.peta.panTo(p.m.getLatLng()));
            }
        },
        hover(id) {
            const p = this.penanda[id];
            if (!p) return;
            this.kelompok.zoomToShowLayer(p.m, () => p.m.openTooltip());
        },
        lokasiSaya() {
            navigator.geolocation?.getCurrentPosition((p) => this.peta.setView([p.coords.latitude, p.coords.longitude], 16));
        },
        gantiLapisan() {
            this.peta.removeLayer(this.lapisan);
            this.satelit = !this.satelit;
            this.lapisan = (this.satelit ? satelit() : ubin()).addTo(this.peta);
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
        init() {
            this.titik = JSON.parse(this.$refs.awal.textContent);
            this.peta = L.map(this.$refs.peta, { zoomControl: false, attributionControl: false }).setView(pusat, 13);
            this.lapisan = ubin().addTo(this.peta);
            L.control.scale({ position: 'topleft', imperial: false }).addTo(this.peta);
            this.lapis = L.layerGroup().addTo(this.peta);
            this.urutkan();
            this.gambar(true);
            navigator.geolocation?.watchPosition((p) => {
                const pertama = !this.posisi;
                this.posisi = { lat: p.coords.latitude, lng: p.coords.longitude, akurasi: p.coords.accuracy };
                if (pertama && !this.desktop) { this.urutkan(); this.gambar(true) } else { this.hitungJarak(); this.gambar() }
            }, () => {}, { enableHighAccuracy: true, maximumAge: 15000 });
            new ResizeObserver(() => this.peta.invalidateSize()).observe(this.$refs.peta);
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
            const r = 6371000, d = Math.PI / 180;
            const x = Math.sin((b.lat - a.lat) * d / 2) ** 2 + Math.cos(a.lat * d) * Math.cos(b.lat * d) * Math.sin((b.lng - a.lng) * d / 2) ** 2;
            return 2 * r * Math.asin(Math.sqrt(x));
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
            this.lapis.clearLayers();
            this.penanda = [];
            const jalur = [this.asal, ...this.urut].filter(Boolean).map((t) => [t.lat, t.lng]);
            if (jalur.length > 1) L.polyline(jalur, { color: '#4556A9', weight: this.desktop ? 4 : 3, opacity: 0.75, dashArray: this.desktop ? null : '6 8' }).addTo(this.lapis);
            if (gudang?.lat && (this.desktop || !this.posisi)) {
                L.marker([gudang.lat, gudang.lng], {
                    interactive: false,
                    icon: L.divIcon({ className: '', html: `<span class="pin-gudang"><span>⌂</span><em>${gudang.nama} · ${gudang.jam}</em></span>`, iconSize: [32, 32], iconAnchor: [16, 16] }),
                }).addTo(this.lapis);
            }
            this.urut.forEach((t, n) => {
                const pilih = n === this.aktif;
                const m = L.marker([t.lat, t.lng], {
                    zIndexOffset: pilih ? 1000 : 0,
                    icon: L.divIcon({
                        className: '',
                        html: `<span class="pin-titik${pilih ? ' dipilih' : ''}"><span class="isi">${t.ikon}</span><span class="nomor">${n + 1}</span></span>`,
                        iconSize: pilih ? [56, 56] : [48, 48],
                        iconAnchor: pilih ? [28, 28] : [24, 24],
                    }),
                }).addTo(this.lapis);
                if (this.desktop) {
                    m.bindTooltip(`${n + 1} · ${t.lokasi}`, { className: 'tip-titik', direction: 'top', offset: [0, -22] });
                    m.bindPopup(this.popup(t, n), { className: 'popup-uj', closeButton: true, minWidth: 288, maxWidth: 312, offset: [0, -14] });
                    m.on('click', () => { this.aktif = n; this.sorotSaja() });
                } else {
                    m.on('click', () => this.pilih(n));
                }
                this.penanda[n] = m;
            });
            if (this.posisi) {
                L.marker([this.posisi.lat, this.posisi.lng], {
                    interactive: false,
                    icon: L.divIcon({ className: '', html: '<span class="pin-saya"><span></span><em>Lokasi saya</em></span>', iconSize: [40, 40], iconAnchor: [20, 20] }),
                }).addTo(this.lapis);
            }
            if (pas && jalur.length) {
                this.peta.fitBounds(jalur, this.desktop
                    ? { paddingTopLeft: [480, 60], paddingBottomRight: [260, 80], maxZoom: 16 }
                    : { padding: [60, 60], paddingBottomRight: [40, 320], maxZoom: 16 });
            }
        },
        sorotSaja() {
            this.penanda.forEach((m, n) => m.setZIndexOffset(n === this.aktif ? 1000 : 0));
        },
        sorot(n) {
            this.penanda[n]?.openTooltip();
        },
        geserKartu() {
            const el = this.$refs.kartu;
            const lebar = el.querySelector(':scope > div')?.offsetWidth ?? el.clientWidth;
            const n = Math.round(el.scrollLeft / (lebar + 8));
            if (n !== this.aktif && this.urut[n]) {
                this.aktif = n;
                this.gambar();
                this.peta.panTo([this.urut[n].lat, this.urut[n].lng]);
            }
        },
        pilih(n, bukaPopup = false) {
            this.aktif = n;
            if (this.desktop) {
                this.gambar();
                const m = this.penanda[n];
                if (m) { this.peta.setView(m.getLatLng(), Math.max(this.peta.getZoom(), 15)); if (bukaPopup) m.openPopup() }
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
            this.peta.removeLayer(this.lapisan);
            this.satelit = !this.satelit;
            this.lapisan = (this.satelit ? satelit() : ubin()).addTo(this.peta);
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
        init() {
            this.titik = JSON.parse(this.$refs.awal.textContent);
            this.peta = L.map(this.$refs.peta, { zoomControl: false, attributionControl: false }).setView(pusat, 13);
            ubin().addTo(this.peta);
            this.lapis = L.featureGroup().addTo(this.peta);
            this.gambar(true);
            navigator.geolocation?.getCurrentPosition((p) => {
                this.gps = { lat: p.coords.latitude, lng: p.coords.longitude };
                L.circleMarker([this.gps.lat, this.gps.lng], { radius: 7, color: '#fff', weight: 3, fillColor: '#1A73E8', fillOpacity: 1 }).addTo(this.peta);
                this.hitung();
            }, () => {}, { enableHighAccuracy: true, timeout: 10000 });
            new ResizeObserver(() => this.peta.invalidateSize()).observe(this.$refs.peta);
        },
        perbarui(titik) {
            this.titik = titik;
            this.aktif = 0;
            this.gambar(true);
            this.hitung();
        },
        gambar(muat = false) {
            this.lapis.clearLayers();
            this.titik.forEach((t, i) => {
                const nomor = `<b style="font:700 14px/1 sans-serif;color:#fff">${t.no}</b>`;
                L.marker([t.lat, t.lng], { icon: pinJenis(i === this.aktif ? '#000100' : '#4556A9', nomor, i === this.aktif), zIndexOffset: i === this.aktif ? 1000 : 0 })
                    .on('click', () => this.pilih(i, true))
                    .addTo(this.lapis);
            });
            if (muat && this.titik.length) this.peta.fitBounds(this.lapis.getBounds(), { paddingTopLeft: [40, 80], paddingBottomRight: [40, 280], maxZoom: 16 });
        },
        pilih(i, gulir = false) {
            if (!this.titik[i]) return;
            this.aktif = i;
            this.gambar();
            this.peta.panTo([this.titik[i].lat, this.titik[i].lng]);
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
            const m = (a, b) => Math.round(this.peta.distance([a.lat, a.lng], [b.lat, b.lng]));
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
        init() {
            const p = L.map(this.$refs.peta, { zoomControl: false, attributionControl: false, scrollWheelZoom: false })
                .setView([titik.lat, titik.lng], 17);
            ubin().addTo(p);
            L.circle([titik.lat, titik.lng], { radius, color: '#4556A9', weight: 1, opacity: 0.4, fillColor: '#4556A9', fillOpacity: 0.08 }).addTo(p);
            sekitar.forEach((b) => L.marker([b.lat, b.lng], { icon: pinJenis('#8A5A00', ikonDuplikat, false, 22), interactive: false }).addTo(p));
            L.marker([titik.lat, titik.lng], { icon: pinJenis('#4556A9', ikon, false, 26), interactive: false }).addTo(p);
            new ResizeObserver(() => p.invalidateSize()).observe(this.$refs.peta);
        },
    }));

    /** Peta kecil statis dengan satu pin (halaman lacak). */
    Alpine.data('petaMini', (lat, lng, ikon) => ({
        init() {
            const p = L.map(this.$el, { zoomControl: false, attributionControl: false, dragging: false, scrollWheelZoom: false, doubleClickZoom: false, touchZoom: false, keyboard: false })
                .setView([lat, lng], 16);
            ubin().addTo(p);
            L.marker([lat, lng], { icon: pinJenis('#4556A9', ikon, true), interactive: false }).addTo(p);
        },
    }));

    /** Peta publik perlengkapan terpasang (beranda). */
    Alpine.data('petaTerpasang', ({ jenis, titik }, pusat) => ({
        init() {
            const lebar = window.innerWidth >= 1024;
            const peta = L.map(this.$refs.peta, { zoomControl: false, scrollWheelZoom: false, attributionControl: true })
                .setView(pusat, 14);
            ubin().addTo(peta);
            L.control.zoom({ position: 'topright' }).addTo(peta);
            const grup = L.featureGroup().addTo(peta);
            titik.forEach((t, i) => {
                const j = jenis[t.jenis] ?? { warna: '#4556A9', ikon: '' };
                L.marker([t.lat, t.lng], { icon: pinJenis(j.warna, j.ikon, i === 0, null, t.rusak ? 'rusak' : '') })
                    .bindPopup(t.popup, { className: 'popup-uj', closeButton: false, maxWidth: 260, autoPan: false })
                    .addTo(grup);
            });
            if (titik.length) {
                // Geser titik ke sisi kanan layar agar tidak tertutup teks hero.
                peta.fitBounds(grup.getBounds(), { paddingTopLeft: [lebar ? 780 : 40, 170], paddingBottomRight: [lebar ? 320 : 40, 250], maxZoom: 16 });
                grup.getLayers()[0].openPopup();
            } else if (lebar) {
                peta.panBy([-380, 0], { animate: false });
            }
            new ResizeObserver(() => peta.invalidateSize()).observe(this.$refs.peta);
        },
    }));

    /** Peta sebaran: titik = [{lat,lng,tone,id,popup, ikon?, warna?}] — dengan `ikon` pin memakai ikon jenis. */
    Alpine.data('petaSebaran', ({ titik = [], pusat, zoom = 13, pilih = null }) => ({
        peta: null,
        lapis: null,
        daftar: [],
        init() {
            this.peta = L.map(this.$refs.peta, { zoomControl: true, scrollWheelZoom: true }).setView(pusat, zoom);
            ubin().addTo(this.peta);
            this.lapis = L.featureGroup().addTo(this.peta);
            this.gambar(titik, pilih);
            new ResizeObserver(() => this.peta.invalidateSize()).observe(this.$refs.peta);
        },
        gambar(daftar, aktif = null) {
            this.daftar = daftar;
            this.lapis.clearLayers();
            daftar.forEach((t) => {
                const ikon = t.ikon
                    ? pinJenis(t.id === aktif ? '#000100' : (t.warna ?? WARNA[t.tone] ?? WARNA.proses), t.ikon, t.id === aktif)
                    : penanda(t.tone, t.id === aktif);
                const m = L.marker([t.lat, t.lng], { icon: ikon, zIndexOffset: t.id === aktif ? 1000 : 0 }).addTo(this.lapis);
                if (t.popup) m.bindPopup(t.popup);
                if (t.id) m.on('click', () => { this.$dispatch('pilih-titik', { id: t.id }); if (t.ikon) this.gambar(this.daftar, t.id) });
            });
            if (daftar.length > 1 && !aktif) this.peta.fitBounds(this.lapis.getBounds(), { padding: [40, 40], maxZoom: 16 });
            if (aktif) {
                const t = daftar.find((x) => x.id === aktif);
                if (t) this.peta.setView([t.lat, t.lng], Math.max(this.peta.getZoom(), 15));
            }
        },
        lokasiSaya() {
            navigator.geolocation?.getCurrentPosition(
                (p) => this.peta.setView([p.coords.latitude, p.coords.longitude], 16),
                () => this.$dispatch('toast', { pesan: 'Lokasi HP tidak bisa dibaca. Nyalakan GPS.', gagal: true }),
                { enableHighAccuracy: true, timeout: 10000 },
            );
        },
    }));

    /** Peta untuk memilih satu titik (pin bisa digeser / tap). */
    Alpine.data('petaPilih', ({ lat, lng, pusat }) => ({
        peta: null,
        pin: null,
        cari: '',
        hasil: [],
        cariBuka: false,
        init() {
            const awal = lat ? [lat, lng] : pusat;
            this.peta = L.map(this.$refs.peta, { zoomControl: false }).setView(awal, lat ? 17 : 13);
            ubin().addTo(this.peta);
            if (lat) this.taruh(lat, lng, false);
            this.peta.on('click', (e) => this.taruh(e.latlng.lat, e.latlng.lng));
            new ResizeObserver(() => this.peta.invalidateSize()).observe(this.$refs.peta);
        },
        taruh(la, ln, kirim = true, geser = false) {
            if (!this.pin) {
                this.pin = L.marker([la, ln], {
                    draggable: true,
                    icon: L.divIcon({
                        className: '',
                        html: `<span class="pin-lokasi"><span class="akurasi"></span><svg viewBox="0 0 24 24" fill="#4556A9" stroke="#fff" stroke-width="1.5"><path d="M12 22s8-6.5 8-13a8 8 0 1 0-16 0c0 6.5 8 13 8 13Z"/><circle cx="12" cy="9" r="3" fill="#fff" stroke="none"/></svg></span>`,
                        iconSize: [36, 36],
                        iconAnchor: [18, 34],
                    }),
                }).addTo(this.peta);
                this.pin.on('dragend', () => { const p = this.pin.getLatLng(); this.kirim(p.lat, p.lng) });
            } else {
                this.pin.setLatLng([la, ln]);
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
            const r = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&countrycodes=id&limit=5&accept-language=id&q=${encodeURIComponent(this.cari)}`);
            this.hasil = await r.json();
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
