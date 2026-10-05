{{-- MD · Judul Langkah: ## 1. Judul → bulatan nomor + judul Anton --}}
@props(['nomor', 'judul'])
<div class="flex items-center gap-3.5">
    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-accent text-base font-bold text-fg-inverse">{{ $nomor }}</span>
    <h2 class="judul text-[26px] leading-[1.1] lg:text-[30px]">{{ $judul }}</h2>
</div>
