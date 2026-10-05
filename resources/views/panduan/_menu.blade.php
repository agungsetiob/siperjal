{{-- Menu samping (desktop): grup peran → artikel. Dibangun dari folder panduan/. --}}
@php $wa = preg_replace('/\D/', '', config('usuljalan.kontak_wa')); @endphp
<nav class="sticky top-16 hidden h-[calc(100dvh-4rem)] w-[280px] shrink-0 flex-col gap-7 overflow-y-auto border-r border-line pb-10 pl-6 pr-5 pt-8 lg:flex" aria-label="Daftar panduan">
    @foreach ($menu as $g)
        <div class="flex flex-col gap-0.5">
            <div class="flex justify-between px-3 pb-2 font-caption text-xs text-fg-muted">
                <span class="font-semibold uppercase tracking-[1.2px]">{{ $g['label'] }}</span>
                <span>{{ $g['artikel']->count() }}</span>
            </div>
            @foreach ($g['artikel'] as $a)
                @php $on = ($aktif['grup'] ?? null) === $a['grup'] && ($aktif['slug'] ?? null) === $a['slug']; @endphp
                <a href="{{ route('panduan.artikel', [$a['grup'], $a['slug']]) }}" @if ($on) aria-current="page" @endif
                   @class(['rounded-md px-3 py-2 text-sm', 'border-l-[3px] border-accent bg-accent-soft font-semibold text-accent' => $on, 'hover:bg-surface-muted' => ! $on])>{{ $a['judul'] }}</a>
            @endforeach
        </div>
    @endforeach
    <div class="mt-auto flex flex-col gap-2.5 border-t border-line px-3 pt-5">
        <span class="text-sm font-semibold">Masih bingung?</span>
        <p class="text-[13px] leading-[1.5] text-fg-muted">Hubungi admin Dishub Kabupaten, Senin–Jumat 08.00–15.00.</p>
        <a href="https://wa.me/62{{ ltrim($wa, '0') }}" target="_blank" rel="noopener" class="flex w-fit items-center gap-2 rounded-md px-3 py-2 text-[13px] font-semibold outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted">@svg('lucide-message-circle', 'size-4 text-success') Chat WhatsApp</a>
    </div>
</nav>
