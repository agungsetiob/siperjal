import leaflet from './leaflet.js';

/**
 * Pilih penyedia peta. `window.PETA` diisi server (partials/peta-config): penyedia 'google' hanya
 * bila admin memilihnya dan kunci API terisi. Google dimuat malas; gagal dimuat → jatuh ke Leaflet.
 */
const cfg = () => window.PETA ?? { penyedia: 'osm' };
const pakaiGoogle = () => cfg().penyedia === 'google' && !!cfg().googleKey;

const muatGoogleAdaptor = () => import('./google.js').then((m) => m.default);

export async function adaptor() {
    return pakaiGoogle() ? muatGoogleAdaptor().catch(() => leaflet) : leaflet;
}

/** Buat peta di `el`; bila Google gagal dimuat/dibuat, peta Leaflet dipakai. */
export async function buatPeta(el, opsi) {
    if (pakaiGoogle()) {
        try {
            return await (await muatGoogleAdaptor()).buatPeta(el, opsi);
        } catch {
            window.dispatchEvent(new CustomEvent('toast', { detail: { gagal: true, judul: 'Google Maps tidak bisa dimuat', pesan: 'Memakai peta OpenStreetMap sementara.' } }));
        }
    }
    return leaflet.buatPeta(el, opsi);
}

export const alamatDari = async (lat, lng) => (await adaptor()).alamatDari(lat, lng);
export const cariAlamat = async (q) => (await adaptor()).cariAlamat(q);

/** Jarak garis lurus dua titik {lat,lng} dalam meter. */
export function jarakMeter(a, b) {
    const r = 6371000, d = Math.PI / 180;
    const x = Math.sin((b.lat - a.lat) * d / 2) ** 2 + Math.cos(a.lat * d) * Math.cos(b.lat * d) * Math.sin((b.lng - a.lng) * d / 2) ** 2;
    return 2 * r * Math.asin(Math.sqrt(x));
}
