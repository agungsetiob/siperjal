{{-- Penampil foto layar penuh. Buka dengan: $dispatch('lihat-foto', { foto: [{ url, ket }], mulai: 0 }) — lihat UsulanFoto::galeri() --}}
<div x-data="{
        buka: false, daftar: [], i: 0, awalX: null,
        tampil(e) { this.daftar = e.detail.foto ?? []; this.i = e.detail.mulai ?? 0; this.buka = this.daftar.length > 0 },
        geser(n) { if (this.daftar.length > 1) this.i = (this.i + n + this.daftar.length) % this.daftar.length },
        lepas(e) { if (this.awalX === null) return; const dx = e.changedTouches[0].clientX - this.awalX; if (Math.abs(dx) > 40) this.geser(dx < 0 ? 1 : -1); this.awalX = null },
     }"
     @lihat-foto.window="tampil($event)"
     @keydown.window="if (! buka) return; if ($event.key === 'Escape') buka = false; if ($event.key === 'ArrowRight') geser(1); if ($event.key === 'ArrowLeft') geser(-1)"
     x-effect="document.body.classList.toggle('overflow-hidden', buka)"
     x-show="buka" x-cloak x-transition.opacity.duration.150ms
     class="fixed inset-0 z-[4000] flex flex-col bg-[#000100]/95 text-fg-inverse backdrop-blur-md" role="dialog" aria-modal="true" aria-label="Foto">
    <div class="flex items-center gap-3 px-4 py-3 sm:px-6">
        <span class="font-caption text-sm font-semibold" x-text="(i + 1) + ' / ' + daftar.length"></span>
        <span class="flex-1 truncate font-caption text-[13px] text-fg-inverse/70" x-text="daftar[i]?.ket"></span>
        <a :href="daftar[i]?.url" target="_blank" class="grid size-10 place-items-center rounded-full hover:bg-fg-inverse/10" aria-label="Buka ukuran asli">@svg('lucide-external-link', 'size-5')</a>
        <button type="button" @click="buka = false" class="grid size-10 place-items-center rounded-full hover:bg-fg-inverse/10" aria-label="Tutup">@svg('lucide-x', 'size-6')</button>
    </div>
    <div class="relative flex min-h-0 flex-1 items-center justify-center px-2 sm:px-20" @click.self="buka = false"
         @touchstart="awalX = $event.touches[0].clientX" @touchend="lepas($event)">
        <template x-for="(f, n) in daftar" :key="f.url">
            <img x-show="n === i" :src="f.url" alt="" class="max-h-full max-w-full rounded-sm object-contain shadow-2xl" draggable="false">
        </template>
        <button type="button" x-show="daftar.length > 1" @click="geser(-1)" class="absolute left-2 top-1/2 grid size-12 -translate-y-1/2 place-items-center rounded-full bg-fg-inverse/10 hover:bg-fg-inverse/20 sm:left-6" aria-label="Foto sebelumnya">@svg('lucide-chevron-left', 'size-6')</button>
        <button type="button" x-show="daftar.length > 1" @click="geser(1)" class="absolute right-2 top-1/2 grid size-12 -translate-y-1/2 place-items-center rounded-full bg-fg-inverse/10 hover:bg-fg-inverse/20 sm:right-6" aria-label="Foto berikutnya">@svg('lucide-chevron-right', 'size-6')</button>
    </div>
    <div x-show="daftar.length > 1" class="flex justify-center gap-2 overflow-x-auto px-4 pb-5 pt-3">
        <template x-for="(f, n) in daftar" :key="'t' + f.url">
            <button type="button" @click="i = n" class="size-14 shrink-0 overflow-hidden rounded-sm transition" :class="n === i ? 'outline outline-2 outline-offset-2 outline-fg-inverse' : 'opacity-50 hover:opacity-80'">
                <img :src="f.url" alt="" class="size-full object-cover">
            </button>
        </template>
    </div>
</div>
