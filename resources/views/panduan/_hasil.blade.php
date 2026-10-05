{{-- Hasil cari (Alpine: cariPanduan). Dipakai di dropdown desktop & sheet HP. --}}
<template x-for="(a, i) in hasil" :key="a.url">
    <a :href="a.url" @mouseenter="sorot = i" :class="sorot === i && 'bg-surface-muted'" class="flex flex-col gap-0.5 rounded-sm px-3 py-2.5">
        <span class="flex items-center gap-2"><span class="text-[15px] font-semibold" x-text="a.judul"></span><span class="font-caption text-[11px] font-semibold uppercase tracking-[1px] text-fg-muted" x-text="a.grup"></span></span>
        <span class="line-clamp-1 text-[13px] text-fg-muted" x-text="a.ringkas"></span>
    </a>
</template>
<p x-show="hasil.length === 0" class="px-3 py-4 text-sm text-fg-muted">Tidak ada panduan untuk “<span x-text="q.trim()"></span>”. Coba kata lain.</p>
