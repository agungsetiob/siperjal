@extends('layouts.panduan', [
    'title' => $artikel['judul'].' · Panduan SIPERJAL',
    'deskripsi' => $artikel['ringkas'],
    'og' => [
        'judul' => $artikel['judul'],
        'deskripsi' => 'Panduan'.($artikel['durasi'] ? ' · '.$artikel['durasi'] : ''),
        'url' => route('panduan.artikel', [$artikel['grup'], $artikel['slug']]),
        'gambar' => \App\Support\Panduan::gambarPratinjau($artikel),
    ],
])

@php
    $aktif = $artikel;
    $bagian = collect($artikel['bagian']);
    $intro = $bagian->firstWhere('jenis', 'intro');
    $isi = $bagian->where('jenis', '!=', 'intro')->where('jenis', '!=', 'selanjutnya');
    $lanjut = $bagian->firstWhere('jenis', 'selanjutnya');
    $kartu = collect($lanjut['blok'] ?? [])->where('t', 'daftar')->flatMap(fn ($d) => $d['butir'])->pluck('tautan')->filter()->values();
    $daftarIsi = $bagian->where('jenis', '!=', 'intro')->map(fn ($b) => [
        'id' => $b['id'],
        'label' => $b['jenis'] === 'langkah' ? $b['nomor'].'. '.$b['judul'] : $b['judul'],
    ])->values();
    $wa = preg_replace('/\D/', '', config('usuljalan.kontak_wa'));
    $dariHp = Str::lower($artikel['perangkat'] ?? '') === 'hp';
@endphp

@section('isi')
<div class="mx-auto flex max-w-[1440px]">
    @include('panduan._menu')

    <main class="min-w-0 flex-1 px-5 pb-16 pt-6 lg:px-8 lg:pb-24 lg:pt-10 xl:px-[72px]">
        <div class="flex gap-16">
            <article class="flex min-w-0 flex-1 flex-col gap-8 lg:gap-14 xl:max-w-[732px]">
                <header class="flex flex-col gap-3 lg:gap-4">
                    <nav aria-label="Jalur" class="hidden items-center gap-1.5 font-caption text-[13px] text-fg-muted lg:flex">
                        <a href="{{ route('panduan') }}" class="hover:text-fg">Panduan</a>
                        @svg('lucide-chevron-right', 'size-3.5')
                        <span>{{ $artikel['peran'] }}</span>
                    </nav>
                    <h1 class="judul text-[36px] leading-[1.04] lg:text-[52px] lg:leading-[1.02]">{{ $artikel['judul'] }}</h1>
                    @if ($artikel['ringkas'])<p class="text-[17px] leading-normal text-fg-muted lg:text-xl">{{ $artikel['ringkas'] }}</p>@endif
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 font-caption text-xs text-fg-muted lg:gap-x-5 lg:pt-1 lg:text-[13px]">
                        @if ($artikel['durasi'])<span class="flex items-center gap-1.5">@svg('lucide-timer', 'size-3.5 lg:size-4') {{ $artikel['durasi'] }}</span>@endif
                        @if ($artikel['perangkat'])<span class="hidden items-center gap-1.5 lg:flex">@svg('lucide-'.($dariHp ? 'smartphone' : 'monitor'), 'size-4') Dilakukan di {{ $artikel['perangkat'] }}</span>@endif
                        @if ($artikel['diperbarui'])<span class="flex items-center gap-1.5">@svg('lucide-calendar', 'size-3.5 lg:size-4') <span><span class="hidden lg:inline">Diperbarui </span>{{ $artikel['diperbarui']->translatedFormat('j M Y') }}</span></span>@endif
                    </div>
                    @foreach ($intro['blok'] ?? [] as $x)
                        @include('panduan._blok', ['x' => $x])
                    @endforeach
                </header>

                @foreach ($isi as $b)
                    @if ($b['jenis'] === 'sebelum')
                        <section id="{{ $b['id'] }}" class="flex scroll-mt-24 flex-col gap-3 rounded-md bg-surface-soft p-5 lg:gap-3.5 lg:px-7 lg:py-6">
                            <h2 class="judul text-xl lg:text-[22px]">{{ $b['judul'] }}</h2>
                            @foreach ($b['blok'] as $x)
                                @if ($x['t'] === 'daftar')
                                    <ul class="flex flex-col gap-3">
                                        @foreach ($x['butir'] as $i)<x-panduan.butir :kecil="true">{!! $i['html'] !!}</x-panduan.butir>@endforeach
                                    </ul>
                                @else
                                    @include('panduan._blok', ['x' => $x])
                                @endif
                            @endforeach
                        </section>
                    @elseif ($b['jenis'] === 'langkah')
                        @php $utama = collect($b['blok'])->first(fn ($x) => $x['t'] === 'gambar' && ! $x['lebarLayar']); @endphp
                        <section id="{{ $b['id'] }}" x-data="{ aktif: 0 }" class="flex scroll-mt-24 flex-col gap-4 lg:gap-6">
                            <x-panduan.judul-langkah :nomor="$b['nomor']" :judul="$b['judul']" />
                            <div class="flex flex-col gap-5 lg:flex-row lg:gap-10">
                                @if ($utama)<div class="hidden shrink-0 lg:block"><x-panduan.gambar :g="$utama" /></div>@endif
                                <div class="flex min-w-0 flex-1 flex-col gap-5 lg:pt-1">
                                    @foreach ($b['blok'] as $x)
                                        @include('panduan._blok', ['x' => $x, 'utama' => $x === $utama])
                                    @endforeach
                                </div>
                            </div>
                        </section>
                    @else
                        <section id="{{ $b['id'] }}" x-data="{ aktif: 0 }" class="flex scroll-mt-24 flex-col gap-4">
                            <h2 class="judul text-[26px] leading-[1.1] lg:text-[30px]">{{ $b['judul'] }}</h2>
                            @foreach ($b['blok'] as $x)
                                @include('panduan._blok', ['x' => $x])
                            @endforeach
                        </section>
                    @endif
                @endforeach

                <footer id="{{ $lanjut['id'] ?? 'umpan-balik' }}" class="flex scroll-mt-24 flex-col gap-5 border-t border-line pt-8 lg:gap-5 lg:pt-10">
                    @if ($kartu->isNotEmpty())
                        <h2 class="judul text-xl lg:text-[22px]">{{ $lanjut['judul'] }}</h2>
                        <div class="flex flex-col gap-3.5 lg:flex-row lg:gap-4">
                            @foreach ($kartu as $i => $k)
                                <a href="{{ route('panduan.artikel', [$k['grup'], $k['slug']]) }}" class="group flex min-w-0 flex-1 flex-col gap-1.5 rounded-md px-4 py-3.5 outline outline-1 -outline-offset-1 outline-line transition hover:bg-surface-muted lg:px-5 lg:py-[18px]">
                                    <span class="font-caption text-[11px] font-semibold uppercase tracking-[1.2px] text-fg-muted lg:text-xs">{{ $i === 0 ? 'Berikutnya' : 'Terkait' }}</span>
                                    <span class="flex items-center gap-2"><span class="min-w-0 flex-1 text-base font-semibold lg:text-[17px]">{{ $k['judul'] }}</span>@svg('lucide-arrow-right', 'size-[18px] shrink-0 text-accent transition group-hover:translate-x-0.5')</span>
                                    @if ($k['ringkas'])<span class="hidden text-sm leading-[1.4] text-fg-muted lg:block">{{ $k['ringkas'] }}</span>@endif
                                </a>
                            @endforeach
                        </div>
                    @endif

                    <div x-data="{ pilih: null }" class="flex flex-col gap-3 pt-4 lg:flex-row lg:items-center lg:gap-4 lg:pt-5">
                        <span class="text-[15px] font-semibold">Apakah panduan ini membantu?</span>
                        <div x-show="! pilih" class="flex gap-3">
                            <button type="button" @click="pilih = 'ya'" class="flex h-11 flex-1 items-center justify-center gap-2 rounded-md text-[15px] font-medium outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted lg:h-auto lg:flex-none lg:px-3.5 lg:py-2 lg:text-sm">@svg('lucide-thumbs-up', 'size-4') Ya</button>
                            <button type="button" @click="pilih = 'belum'" class="flex h-11 flex-1 items-center justify-center gap-2 rounded-md text-[15px] font-medium outline outline-1 -outline-offset-1 outline-line hover:bg-surface-muted lg:h-auto lg:flex-none lg:px-3.5 lg:py-2 lg:text-sm">@svg('lucide-thumbs-down', 'size-4') Belum</button>
                        </div>
                        <p x-show="pilih === 'ya'" x-cloak class="flex items-center gap-2 text-[15px] text-success">@svg('lucide-check', 'size-4') Terima kasih!</p>
                        <p x-show="pilih === 'belum'" x-cloak class="text-[15px] text-fg-muted">Maaf belum membantu. <a href="https://wa.me/62{{ ltrim($wa, '0') }}" target="_blank" rel="noopener" class="font-semibold text-accent underline underline-offset-2">Tanya admin lewat WhatsApp</a></p>
                    </div>
                </footer>
            </article>

            <aside class="sticky top-24 hidden w-[220px] shrink-0 flex-col gap-6 self-start xl:flex" x-data="daftarIsiPanduan(@js($daftarIsi->pluck('id')->all()))">
                <div class="flex flex-col gap-3.5 pt-2">
                    <span class="font-caption text-xs font-semibold uppercase tracking-[1.2px] text-fg-muted">Di halaman ini</span>
                    <div class="flex flex-col border-l border-line">
                        @foreach ($daftarIsi as $d)
                            <a href="#{{ $d['id'] }}" :class="aktif === '{{ $d['id'] }}' ? 'border-accent font-semibold text-accent' : 'border-transparent text-fg-muted hover:text-fg'" class="-ml-px border-l-2 py-1.5 pl-3.5 text-[13px] leading-[1.4]">{{ $d['label'] }}</a>
                        @endforeach
                    </div>
                </div>
                @if ($dariHp)
                    <div class="flex flex-col gap-2.5 rounded-md bg-surface-muted p-4" x-data="{ ok: false }">
                        <span class="flex items-center gap-2 text-[13px] font-semibold">@svg('lucide-smartphone', 'size-4') Ikuti dari HP</span>
                        <p class="text-xs leading-[1.5] text-fg-muted">Panduan ini paling mudah diikuti sambil memegang HP. Kirim tautannya ke HP-mu.</p>
                        <button type="button" @click="salin(location.href.split('#')[0]).then(() => { ok = true; setTimeout(() => ok = false, 1800) })" class="flex w-fit items-center gap-1.5 text-[13px] font-semibold text-accent">
                            @svg('lucide-link', 'size-3.5') <span x-text="ok ? 'Tautan disalin' : 'Salin tautan'">Salin tautan</span>
                        </button>
                    </div>
                @endif
            </aside>
        </div>
    </main>
</div>
@endsection
