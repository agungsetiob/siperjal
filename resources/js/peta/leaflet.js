import { mentah } from './util.js';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';

/**
 * Adaptor peta: Leaflet + OpenStreetMap (gratis, tanpa kunci).
 * Antarmuka sama dengan ./google.js — komponen di app.js hanya memanggil adaptor.
 */
const ubin = () => L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' });
const satelit = () => L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: 'Citra &copy; Esri' });

const ikonDari = (d) => L.divIcon({ className: '', html: d.html, iconSize: d.ukuran, iconAnchor: d.jangkar, popupAnchor: d.popupAnchor ?? [0, 0] });
const titikDari = (a) => (Array.isArray(a) ? a : [a.lat, a.lng]);

class Penanda {
    constructor(p, o) { mentah(this);
        this.p = p;
        this.l = L.marker(titikDari(o), {
            icon: ikonDari(o),
            zIndexOffset: o.z ?? 0,
            interactive: o.interaktif !== false,
            draggable: !!o.geser,
        });
        if (o.tooltip) this.l.bindTooltip(o.tooltip.html, { className: o.tooltip.kelas, direction: 'top', offset: o.tooltip.offset });
        if (o.popup) {
            const q = o.popup;
            const opsi = {
                className: q.kelas,
                closeButton: q.tutup ?? true,
                maxWidth: q.lebarMaks,
                minWidth: q.lebarMin,
                offset: q.offset,
                autoPan: q.autoPan !== false,
            };
            // Leaflet memakai nilai bawaan hanya bila opsi tidak diisi (undefined menimpanya)
            Object.keys(opsi).forEach((k) => opsi[k] === undefined && delete opsi[k]);
            if (q.autoGeser) Object.assign(opsi, { autoPanPaddingTopLeft: L.point(...q.autoGeser.kiriAtas), autoPanPaddingBottomRight: L.point(...q.autoGeser.kananBawah) });
            this.l.bindPopup(q.html, opsi);
        }
        if (o.klik) this.l.on('click', o.klik);
        if (o.geserSelesai) this.l.on('dragend', () => { const q = this.l.getLatLng(); o.geserSelesai(q.lat, q.lng) });
    }

    kePeta() { this.p.l.addLayer(this.l) }
    hapus() { this.p.l.removeLayer(this.l) }
    ikon(d) { this.l.setIcon(ikonDari(d)) }
    z(n) { this.l.setZIndexOffset(n) }
    opacity(n) { this.l.setOpacity(n) }
    posisi() { const q = this.l.getLatLng(); return { lat: q.lat, lng: q.lng } }
    setPosisi(lat, lng) { this.l.setLatLng([lat, lng]) }
    bukaPopup() { this.l.openPopup() }
    bukaTooltip() { this.l.openTooltip() }
}

class Bentuk {
    constructor(p, l) { mentah(this); this.p = p; this.l = l }
    kePeta() { this.p.l.addLayer(this.l) }
    hapus() { this.p.l.removeLayer(this.l) }
}

/** Kumpulan objek yang bisa dikosongkan sekaligus. */
class Grup {
    constructor(p) { mentah(this); this.p = p; this.isi = [] }
    tambah(w) { this.isi.push(w); w.kePeta() }
    kosongkan() { this.isi.forEach((w) => w.hapus()); this.isi = [] }
}

class Kelompok {
    constructor(p, o) { mentah(this);
        this.p = p;
        this.l = L.markerClusterGroup({
            showCoverageOnHover: false,
            maxClusterRadius: o.radius ?? 44,
            iconCreateFunction: (c) => L.divIcon({ className: '', html: o.html(c.getChildCount()), iconSize: [o.ukuran, o.ukuran] }),
        });
        p.l.addLayer(this.l);
    }

    tambah(w) { this.l.addLayer(w.l) }
    zoomKe(w, cb) { this.l.zoomToShowLayer(w.l, cb) }
}

class Peta {
    constructor(el, o) { mentah(this);
        const statis = !!o.statis;
        this.l = L.map(el, {
            zoomControl: false,
            attributionControl: !!o.atribusi,
            scrollWheelZoom: o.scrollZoom !== false && !statis,
            ...(statis ? { dragging: false, doubleClickZoom: false, touchZoom: false, keyboard: false } : {}),
        }).setView(o.pusat, o.zoom ?? 13);
        this.lapisan = ubin().addTo(this.l);
        if (o.zoomKontrol) L.control.zoom({ position: 'topright' }).addTo(this.l);
        if (o.skala) L.control.scale({ position: 'topleft', imperial: false }).addTo(this.l);
        new ResizeObserver(() => this.l.invalidateSize()).observe(el);
    }

    setView(ll, zoom) { this.l.setView(titikDari(ll), zoom) }
    panTo(ll) { this.l.panTo(titikDari(ll)) }
    panBy(xy, animasi = true) { this.l.panBy(xy, { animate: animasi }) }
    getZoom() { return this.l.getZoom() }
    fitBounds(titik, opsi = {}) { this.l.fitBounds(titik, opsi) }
    siap() { return true }
    dalamBatas(ll) { return this.l.getBounds().contains(titikDari(ll)) }
    pada(kejadian, fn) {
        if (kejadian === 'klik') this.l.on('click', (e) => fn({ lat: e.latlng.lat, lng: e.latlng.lng }));
        else this.l.on('moveend', fn);
    }

    gantiLapisan(sat) {
        this.l.removeLayer(this.lapisan);
        this.lapisan = (sat ? satelit() : ubin()).addTo(this.l);
    }

    tambah(w) { w.kePeta() }
    grup() { return new Grup(this) }
    kelompok(o) { return new Kelompok(this, o) }

    penanda(o) {
        const m = new Penanda(this, o);
        (o.dalam ?? this).tambah(m);
        return m;
    }

    garis(titik, o, dalam = null) {
        const w = new Bentuk(this, L.polyline(titik, { color: o.warna, weight: o.tebal, opacity: o.opacity, dashArray: o.putus ?? null }));
        (dalam ?? this).tambah(w);
        return w;
    }

    lingkaran(ll, radius, o, dalam = null) {
        const w = new Bentuk(this, L.circle(titikDari(ll), { radius, color: o.warna, weight: 1, opacity: 0.4, fillColor: o.warna, fillOpacity: 0.08 }));
        (dalam ?? this).tambah(w);
        return w;
    }
}

export default {
    nama: 'osm',
    buatPeta: async (el, o) => new Peta(el, o),

    /** Alamat dari koordinat (OpenStreetMap Nominatim). */
    async alamatDari(lat, lng) {
        try {
            const r = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&accept-language=id&lat=${lat}&lon=${lng}`);
            const a = (await r.json()).address ?? {};
            return {
                jalan: [a.road, a.neighbourhood, a.hamlet].filter(Boolean).join(', '),
                // Kandidat nama; server (App\Support\Wilayah) memilih yang cocok dengan data wilayah.
                desa: [a.village, a.suburb, a.quarter, a.hamlet, a.neighbourhood].filter(Boolean),
                kecamatan: [a.city_district, a.municipality, a.town, a.suburb, a.city].filter(Boolean),
            };
        } catch {
            return { jalan: '', desa: [], kecamatan: [] };
        }
    },

    /** Cari alamat → [{display_name, lat, lon}]. */
    async cariAlamat(q) {
        const r = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&countrycodes=id&limit=5&accept-language=id&q=${encodeURIComponent(q)}`);
        return r.json();
    },
};
