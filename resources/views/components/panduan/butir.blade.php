{{-- MD · Butir: butir daftar dengan centang --}}
@props(['kecil' => false])
<li class="flex gap-3">
    @svg('lucide-check', 'mt-[3px] size-[18px] shrink-0 text-accent')
    <span @class(['md-isi leading-normal', 'text-[15px] lg:text-base' => $kecil, 'text-base' => ! $kecil])>{{ $slot }}</span>
</li>
