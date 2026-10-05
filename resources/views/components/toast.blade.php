{{-- Toast dengan Urungkan. Dipicu: $this->dispatch('toast', pesan: '...', urungkan: $token). Dari JS bisa juga { judul, aksi, ulang: fn } (mis. "Coba lagi"). --}}
<div x-data="{
        daftar: [],
        tampil(e) {
            const t = { id: Date.now(), ...e.detail };
            this.daftar.push(t);
            setTimeout(() => this.tutup(t.id), t.aksi ? 8000 : (t.urungkan ? 5000 : 3500));
        },
        tutup(id) { this.daftar = this.daftar.filter(t => t.id !== id) },
        urungkan(t) { Livewire.dispatch('urungkan', { token: t.urungkan }); this.tutup(t.id) },
        ulangi(t) { t.ulang(); this.tutup(t.id) },
    }"
    @toast.window="tampil($event)"
    class="pointer-events-none fixed inset-x-0 bottom-24 z-[2000] flex flex-col items-center gap-2 px-4 lg:bottom-6 lg:left-6 lg:right-auto lg:items-start lg:px-0">
    <template x-for="t in daftar" :key="t.id">
        <div x-transition.opacity.duration.200ms class="pointer-events-auto flex w-full max-w-[392px] items-center gap-2.5 rounded-md bg-fg py-3 pl-4 pr-3 text-[13px] font-medium text-fg-inverse shadow-xl">
            <span x-show="!t.gagal" class="text-success-bg">{!! svg('lucide-circle-check', 'size-[18px]')->toHtml() !!}</span>
            <span x-show="t.gagal" :class="t.aksi ? 'text-[#FF8A8A]' : 'text-warning-bg'">{!! svg('lucide-circle-alert', 'size-[18px]')->toHtml() !!}</span>
            <span class="flex flex-1 flex-col gap-0.5">
                <span x-show="t.judul" class="text-sm font-semibold" x-text="t.judul"></span>
                <span :class="t.judul && 'font-caption text-xs font-normal text-fg-inverse/70'" x-text="t.pesan"></span>
            </span>
            <button x-show="t.aksi" @click="ulangi(t)" class="flex items-center gap-1.5 rounded-sm bg-fg-inverse/10 px-2.5 py-1.5 font-caption text-[13px] font-semibold hover:bg-fg-inverse/20">{!! svg('lucide-rotate-cw', 'size-3.5')->toHtml() !!} <span x-text="t.aksi"></span></button>
            <button x-show="t.urungkan" @click="urungkan(t)" class="rounded-sm px-2 py-1 font-caption font-bold text-secondary hover:bg-fg-inverse/10">Urungkan</button>
            <span class="h-[18px] w-px bg-fg-inverse/20"></span>
            <button @click="tutup(t.id)" class="text-fg-inverse/70 hover:text-fg-inverse" aria-label="Tutup">{!! svg('lucide-x', 'size-4')->toHtml() !!}</button>
        </div>
    </template>
</div>
