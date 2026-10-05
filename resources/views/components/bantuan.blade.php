{{-- Tombol ? di bar atas (desktop): panduan untuk layar yang sedang dibuka lebih dulu. Data diambil saat menu dibuka (PanduanController@menu). --}}
<div class="relative hidden lg:block" x-data="menuBantuan(@js(request()->route()?->getName()), @js(route('panduan.menu')))"
     @keydown.window="tekan($event)" @keydown.escape.window="buka = false" @click.outside="buka = false">
    <button type="button" @click="toggle()" :class="buka ? 'bg-accent-soft text-accent' : 'hover:bg-surface-muted'" class="grid size-10 place-items-center rounded-sm" aria-label="Panduan dan bantuan" :aria-expanded="buka">
        @svg('lucide-circle-help', 'size-[22px]')
    </button>

    <div x-show="buka" x-cloak x-transition.opacity.duration.150ms class="absolute right-0 top-12 z-[1100] w-[360px] rounded-lg bg-bg py-2 shadow-[0_12px_32px_#1D255426] outline outline-1 -outline-offset-1 outline-line">
        <div x-show="memuat" class="flex flex-col gap-2 px-4 pb-3 pt-2">
            <span class="kerangka h-3 w-40"></span><span class="kerangka h-12 w-full"></span>
        </div>

        <div x-show="data?.artikel?.length" class="flex flex-col gap-0.5 px-2 pb-2.5 pt-2">
            <div class="px-2 pb-2 pt-1 font-caption text-[11px] font-semibold uppercase tracking-[1.2px] text-fg-muted">Untuk layar ini<span x-show="data?.nama"> · <span x-text="data?.nama"></span></span></div>
            <template x-for="(a, i) in (data ? data.artikel : [])" :key="a.url">
                <a :href="a.url" target="_blank" rel="noopener" :class="i === 0 ? 'bg-surface-muted' : 'hover:bg-surface-muted'" class="flex items-center gap-3 rounded-md px-2 py-2.5">
                    <span class="grid size-8 shrink-0 place-items-center rounded-md bg-accent-soft text-accent">@svg('lucide-book-open', 'size-4')</span>
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5"><span class="truncate text-sm font-semibold" x-text="a.judul"></span><span class="font-caption text-xs text-fg-muted" x-text="a.ket"></span></span>
                    <span class="shrink-0 text-accent">@svg('lucide-arrow-right', 'size-4')</span>
                </a>
            </template>
        </div>

        <div :class="data?.artikel?.length && 'border-t border-line'" class="flex flex-col gap-0.5 px-2 pb-1 pt-2">
            <a href="{{ route('panduan') }}" target="_blank" rel="noopener" class="flex items-center gap-3 rounded-md px-2 py-2.5 text-sm font-medium hover:bg-surface-muted">@svg('lucide-library', 'size-[18px]') <span class="flex-1">Semua panduan</span> @svg('lucide-arrow-up-right', 'size-3.5 text-fg-muted')</a>
        </div>

        <div class="flex items-center gap-2 border-t border-line px-4 pb-1.5 pt-2.5 font-caption text-xs text-fg-muted">
            <kbd class="rounded-sm bg-surface-muted px-1.5 font-mono text-xs text-fg outline outline-1 -outline-offset-1 outline-line">?</kbd> Tekan ? di mana saja untuk membuka menu ini
        </div>
    </div>
</div>
