import { MarkerClusterer } from '@googlemaps/markerclusterer';
import { mentah } from './util.js';

/**
 * Adaptor peta: Google Maps JavaScript API (butuh kunci API + billing aktif di Google Cloud).
 * Antarmuka sama dengan ./leaflet.js. Dimuat malas — hanya bila admin memilih Google di Pengaturan → Peta.
 * API yang harus aktif di kunci: Maps JavaScript API & Geocoding API.
 */
const kabar = (nama, detail) => window.dispatchEvent(new CustomEvent(nama, { detail }));

let memuat = null;
/** Muat skrip Google sekali saja; gagal → Promise ditolak (pemanggil jatuh ke Leaflet). */
function muatGoogle(kunci) {
    memuat ??= new Promise((ok, gagal) => {
        window.gm_authFailure = () => kabar('toast', {
            gagal: true,
            judul: 'Kunci Google Maps ditolak',
            pesan: 'Periksa kunci API, billing, dan pembatasan domainnya di Pengaturan → Peta.',
        });
        window.__petaGoogleSiap = ok;
        const s = document.createElement('script');
        s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(kunci)}&loading=async&v=weekly&language=id&region=ID&callback=__petaGoogleSiap`;
        s.async = true;
        s.onerror = () => { memuat = null; gagal(new Error('Google Maps tidak bisa dimuat')) };
        document.head.append(s);
    }).then(async () => ({
        maps: await google.maps.importLibrary('maps'),
        marker: await google.maps.importLibrary('marker'),
        geocoding: await google.maps.importLibrary('geocoding'),
    }));
    return memuat;
}

const ll = (a) => (Array.isArray(a) ? { lat: a[0], lng: a[1] } : { lat: a.lat, lng: a.lng });
const angka = (x) => (typeof x === 'function' ? x() : x);

/** Pasang ikon HTML: AdvancedMarker menempelkan tengah-bawah isi ke titik, geser agar jangkar ikon tepat di titik. */
function pasangIkon(el, d, interaktif) {
    const [w, h] = d.ukuran, [ax, ay] = d.jangkar;
    el.style.cssText = `position:relative;width:${w}px;height:${h}px;transform:translate(${w / 2 - ax}px,${h - ay}px);pointer-events:${interaktif ? 'auto' : 'none'}`;
    el.innerHTML = d.html;
}

const elemen = (html) => { const e = document.createElement('div'); e.innerHTML = html; return e };

class Penanda {
    constructor(p, o, lib) { mentah(this);
        this.p = p;
        this.o = o;
        this.pos = ll(o);
        this.el = document.createElement('div');
        pasangIkon(this.el, o, o.interaktif !== false);
        this.g = new lib.marker.AdvancedMarkerElement({
            position: this.pos,
            content: this.el,
            zIndex: o.z ?? 0,
            gmpDraggable: !!o.geser,
            gmpClickable: o.interaktif !== false,
        });
        if (o.tooltip) {
            this.el.addEventListener('mouseenter', () => this.bukaTooltip());
            this.el.addEventListener('mouseleave', () => p.tip.close());
        }
        if (o.klik) this.g.addListener('click', () => o.klik());
        if (o.popup) this.g.addListener('click', () => this.bukaPopup());
        if (o.geser) {
            this.g.addListener('dragend', () => {
                this.pos = { lat: angka(this.g.position.lat), lng: angka(this.g.position.lng) };
                o.geserSelesai?.(this.pos.lat, this.pos.lng);
            });
        }
    }

    kePeta() { this.g.map = this.p.g }
    hapus() { this.g.map = null }
    ikon(d) { pasangIkon(this.el, d, this.o.interaktif !== false) }
    z(n) { this.g.zIndex = n }
    opacity(n) { this.el.style.opacity = n; this.el.style.pointerEvents = n ? 'auto' : 'none' }
    posisi() { return this.pos }
    setPosisi(lat, lng) { this.pos = { lat, lng }; this.g.position = this.pos }
    bukaPopup() {
        const q = this.o.popup;
        this.p.pop.setOptions({ maxWidth: q.lebarMaks ?? 320, headerDisabled: !(q.tutup ?? true), disableAutoPan: q.autoPan === false });
        this.p.pop.setContent(elemen(q.html));
        this.p.pop.open({ map: this.p.g, anchor: this.g });
    }

    bukaTooltip() {
        if (!this.o.tooltip) return;
        this.p.tip.setContent(elemen(this.o.tooltip.html));
        this.p.tip.open({ map: this.p.g, anchor: this.g });
    }
}

class Bentuk {
    constructor(p, g) { mentah(this); this.p = p; this.g = g }
    kePeta() { this.g.setMap(this.p.g) }
    hapus() { this.g.setMap(null) }
}

class Grup {
    constructor(p) { mentah(this); this.p = p; this.isi = [] }
    tambah(w) { this.isi.push(w); w.kePeta() }
    kosongkan() { this.isi.forEach((w) => w.hapus()); this.isi = [] }
}

class Kelompok {
    constructor(p, o, lib) { mentah(this);
        this.p = p;
        this.c = new MarkerClusterer({
            map: p.g,
            markers: [],
            algorithmOptions: { radius: o.radius ?? 44 },
            renderer: {
                render: ({ count, position }) => {
                    const el = document.createElement('div');
                    pasangIkon(el, { html: o.html(count), ukuran: [o.ukuran, o.ukuran], jangkar: [o.ukuran / 2, o.ukuran / 2] }, true);
                    return new lib.marker.AdvancedMarkerElement({ position, content: el, zIndex: 1000 + count });
                },
            },
        });
    }

    tambah(w) { this.c.addMarker(w.g) }
    /** Dekatkan peta sampai penanda tampil sendiri (tidak terkelompok), lalu panggil cb. */
    zoomKe(w, cb) {
        if (w.g.map) return cb();
        this.p.g.setCenter(w.pos);
        this.p.g.setZoom(Math.max(this.p.g.getZoom() ?? 0, 17));
        google.maps.event.addListenerOnce(this.p.g, 'idle', () => cb());
    }
}

class Peta {
    constructor(el, o, lib) { mentah(this);
        this.lib = lib;
        this.g = new lib.maps.Map(el, {
            center: ll(o.pusat),
            zoom: o.zoom ?? 13,
            mapId: 'DEMO_MAP_ID', // wajib untuk AdvancedMarker
            disableDefaultUI: true,
            zoomControl: !!o.zoomKontrol,
            scaleControl: !!o.skala,
            clickableIcons: false,
            keyboardShortcuts: !o.statis,
            gestureHandling: o.statis ? 'none' : (o.scrollZoom === false ? 'cooperative' : 'greedy'),
            disableDoubleClickZoom: !!o.statis,
        });
        this.tip = new lib.maps.InfoWindow({ headerDisabled: true, disableAutoPan: true });
        this.pop = new lib.maps.InfoWindow();
    }

    setView(a, zoom) {
        this.g.setCenter(ll(a));
        if (zoom != null) this.g.setZoom(zoom);
    }

    panTo(a) { this.g.panTo(ll(a)) }
    panBy(xy) { this.g.panBy(xy[0], xy[1]) }
    getZoom() { return this.g.getZoom() }

    fitBounds(titik, opsi = {}) {
        if (!titik.length) return;
        const b = new google.maps.LatLngBounds();
        titik.forEach((t) => b.extend(ll(t)));
        const kiriAtas = opsi.paddingTopLeft ?? opsi.padding ?? [0, 0];
        const kananBawah = opsi.paddingBottomRight ?? opsi.padding ?? [0, 0];
        this.g.fitBounds(b, { left: kiriAtas[0], top: kiriAtas[1], right: kananBawah[0], bottom: kananBawah[1] });
        if (opsi.maxZoom) {
            google.maps.event.addListenerOnce(this.g, 'idle', () => {
                if ((this.g.getZoom() ?? 0) > opsi.maxZoom) this.g.setZoom(opsi.maxZoom);
            });
        }
    }

    siap() { return !!this.g.getBounds() }
    dalamBatas(a) { return !!this.g.getBounds()?.contains(ll(a)) }
    pada(kejadian, fn) {
        if (kejadian === 'klik') this.g.addListener('click', (e) => fn({ lat: e.latLng.lat(), lng: e.latLng.lng() }));
        else this.g.addListener('idle', fn);
    }

    gantiLapisan(sat) { this.g.setMapTypeId(sat ? 'satellite' : 'roadmap') }
    tambah(w) { w.kePeta() }
    grup() { return new Grup(this) }
    kelompok(o) { return new Kelompok(this, o, this.lib) }

    penanda(o) {
        const m = new Penanda(this, o, this.lib);
        (o.dalam ?? this).tambah(m);
        return m;
    }

    garis(titik, o, dalam = null) {
        const garis = new google.maps.Polyline({
            path: titik.map(ll),
            strokeColor: o.warna,
            strokeWeight: o.tebal,
            strokeOpacity: o.putus ? 0 : o.opacity,
            icons: o.putus ? [{ icon: { path: 'M 0,-1 0,1', strokeOpacity: o.opacity, scale: o.tebal }, offset: '0', repeat: '14px' }] : [],
        });
        const w = new Bentuk(this, garis);
        (dalam ?? this).tambah(w);
        return w;
    }

    lingkaran(a, radius, o, dalam = null) {
        const w = new Bentuk(this, new google.maps.Circle({
            center: ll(a), radius, strokeColor: o.warna, strokeWeight: 1, strokeOpacity: 0.4, fillColor: o.warna, fillOpacity: 0.08,
        }));
        (dalam ?? this).tambah(w);
        return w;
    }
}

const unik = (daftar) => [...new Set(daftar)];

export default {
    nama: 'google',
    async buatPeta(el, o) {
        return new Peta(el, o, await muatGoogle(window.PETA.googleKey));
    },

    /** Alamat dari koordinat (Google Geocoding), dipetakan ke bentuk yang sama dengan Nominatim. */
    async alamatDari(lat, lng) {
        try {
            await muatGoogle(window.PETA.googleKey);
            const { results } = await new google.maps.Geocoder().geocode({ location: { lat, lng }, language: 'id' });
            const nama = (...jenis) => unik(results.flatMap((r) => r.address_components)
                .filter((c) => jenis.some((j) => c.types.includes(j))).map((c) => c.long_name));
            return {
                jalan: unik([...nama('route').slice(0, 1), ...nama('neighborhood').slice(0, 1)]).join(', '),
                desa: nama('administrative_area_level_4', 'sublocality_level_1', 'sublocality', 'neighborhood'),
                kecamatan: nama('administrative_area_level_3', 'sublocality_level_2', 'locality'),
            };
        } catch {
            return { jalan: '', desa: [], kecamatan: [] };
        }
    },

    /** Cari alamat → [{display_name, lat, lon}] (bentuk sama dengan Nominatim). */
    async cariAlamat(q) {
        try {
            await muatGoogle(window.PETA.googleKey);
            const { results } = await new google.maps.Geocoder().geocode({ address: q, region: 'id', componentRestrictions: { country: 'ID' }, language: 'id' });
            return results.slice(0, 5).map((r) => ({ display_name: r.formatted_address, lat: r.geometry.location.lat(), lon: r.geometry.location.lng() }));
        } catch {
            return [];
        }
    },
};
