@extends('layouts.panduan', ['title' => 'Panduan · SIPERJAL', 'deskripsi' => 'Panduan memakai SIPERJAL untuk pengusul, Dishub, Bappeda, dan petugas lapangan.'])

@section('isi')
<div class="mx-auto flex max-w-[1440px]">
    @include('panduan._menu')

    <main class="min-w-0 flex-1 px-5 pb-16 pt-6 lg:px-8 lg:pb-24 lg:pt-10 xl:px-[72px]">
        <div class="flex max-w-[732px] flex-col gap-8 lg:gap-12">
            <header class="flex flex-col gap-3 lg:gap-4">
                <h1 class="judul text-[36px] leading-[1.04] lg:text-[52px] lg:leading-[1.02]">Panduan</h1>
                <p class="text-[17px] leading-normal text-fg-muted lg:text-xl">Pilih panduan sesuai peranmu. Setiap panduan bisa diikuti langkah demi langkah.</p>
            </header>

            @forelse ($menu as $g)
                <section class="flex flex-col gap-3">
                    <h2 class="font-caption text-xs font-semibold uppercase tracking-[1.2px] text-fg-muted">{{ $g['label'] }}</h2>
                    <div class="flex flex-col gap-3">
                        @foreach ($g['artikel'] as $a)
                            <a href="{{ route('panduan.artikel', [$a['grup'], $a['slug']]) }}" class="group flex items-center gap-4 rounded-md px-4 py-3.5 outline outline-1 -outline-offset-1 outline-line transition hover:bg-surface-muted lg:px-5 lg:py-4">
                                <span class="flex min-w-0 flex-1 flex-col gap-1">
                                    <span class="text-base font-semibold lg:text-[17px]">{{ $a['judul'] }}</span>
                                    @if ($a['ringkas'])<span class="text-sm leading-[1.4] text-fg-muted">{{ $a['ringkas'] }}</span>@endif
                                    @if ($a['durasi'])<span class="mt-0.5 flex items-center gap-1.5 font-caption text-xs text-fg-muted">@svg('lucide-timer', 'size-3.5') {{ $a['durasi'] }}</span>@endif
                                </span>
                                @svg('lucide-arrow-right', 'size-[18px] shrink-0 text-accent transition group-hover:translate-x-0.5')
                            </a>
                        @endforeach
                    </div>
                </section>
            @empty
                <x-kosong ikon="book-open" judul="Belum ada panduan" teks="Tambahkan file .md di folder panduan/." />
            @endforelse
        </div>
    </main>
</div>
@endsection
