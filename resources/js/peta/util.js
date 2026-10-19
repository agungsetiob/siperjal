/** Tandai objek agar tidak dibungkus Proxy reaktif Alpine (objek Leaflet/Google tidak tahan Proxy). */
export const mentah = (o) => Object.defineProperty(o, '__v_skip', { value: true });
