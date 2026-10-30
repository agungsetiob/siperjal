{{-- Dialog konfirmasi pengganti confirm() bawaan peramban. Dipicu: $dispatch('konfirmasi', { judul, teks, tombol: 'Hapus', bahaya: true, aksi: () => $wire.hapus(id) }). --}}
<div x-data="{
        buka: false, judul: '', teks: '', tombol: 'Ya', bahaya: true, aksi: null,
        tampil(e) {
            const d = e.detail;
            Object.assign(this, { judul: d.judul ?? 'Yakin?', teks: d.teks ?? '', tombol: d.tombol ?? 'Ya', bahaya: d.bahaya ?? true, aksi: d.aksi ?? null, buka: true });
            this.$nextTick(() => this.$refs.ya.focus());
        },
        setuju() { const f = this.aksi; this.buka = false; this.aksi = null; f && f() },
    }"
    @konfirmasi.window="tampil($event)" @keydown.escape.window="buka = false"
    x-show="buka" x-cloak x-transition.opacity.duration.150ms
    class="fixed inset-0 z-[3500] flex items-end justify-center bg-fg/50 sm:items-center" @click.self="buka = false"
    role="alertdialog" aria-modal="true">
    <div class="flex w-full flex-col gap-4 rounded-t-xl bg-bg p-6 shadow-xl sm:max-w-sm sm:rounded-md">
        <div class="flex items-start gap-3">
            <span x-show="bahaya" class="grid size-10 shrink-0 place-items-center rounded-full bg-danger-bg text-danger">{!! svg('lucide-trash-2', 'size-5')->toHtml() !!}</span>
            <div class="flex min-w-0 flex-col gap-1">
                <h3 class="judul text-2xl" x-text="judul"></h3>
                <p x-show="teks" class="text-sm leading-[21px] text-fg-muted" x-text="teks"></p>
            </div>
        </div>
        <div class="flex flex-col-reverse gap-2 pt-1 sm:flex-row sm:justify-end">
            <x-tombol variant="teks" @click="buka = false">Batal</x-tombol>
            <button type="button" x-ref="ya" @click="setuju()" :class="bahaya ? 'bg-danger hover:brightness-110' : 'bg-accent hover:brightness-110'" class="inline-flex items-center justify-center gap-2 rounded-sm px-4 py-2.5 text-[15px] font-semibold text-fg-inverse transition" x-text="tombol"></button>
        </div>
    </div>
</div>
