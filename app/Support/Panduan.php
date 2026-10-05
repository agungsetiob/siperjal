<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * Panduan ditulis di Markdown (`panduan/{grup}/{nn}-{slug}.md`). Menu samping, daftar isi, dan halaman
 * dibangun dari isi file. Sintaks → komponen: lihat `panduan/README.md`.
 */
class Panduan
{
    /** Urutan grup di menu samping; grup lain menyusul menurut abjad. */
    public const URUTAN_GRUP = ['umum', 'pengusul', 'dishub', 'bappeda', 'petugas-lapangan', 'admin'];

    /** Nama layar aplikasi → label di menu bantuan. Artikel menyebut layarnya lewat `layar: [..]` di frontmatter. */
    public const LAYAR = [
        'usulan-saya' => 'Usulan saya',
        'ajukan-usulan' => 'Ajukan usulan',
        'revisi' => 'Perbaiki usulan',
        'laporan-saya' => 'Laporan saya',
        'lapor-kerusakan' => 'Lapor kerusakan',
        'usulan-masuk' => 'Usulan masuk',
        'siap-dipasang' => 'Siap dipasang',
        'keputusan' => 'Keputusan usulan',
        'pagu-ditunda' => 'Ditunda & pagu',
        'pemeliharaan' => 'Pemeliharaan',
        'laporan' => 'Laporan',
        'pengaturan-ssh' => 'Harga SSH',
        'pengaturan-pengguna' => 'Pengguna & instansi',
        'pengaturan-wilayah' => 'Wilayah & jalan',
        'profil' => 'Profil',
        'notifikasi' => 'Notifikasi',
        'masuk' => 'Masuk',
    ];

    /** Jenis kabar → artikel panduan yang disertakan di pesan (email; WhatsApp bila nanti tersambung). */
    private const UNTUK_KABAR = [
        'revisi' => ['pengusul', 'perbaiki-revisi', 'Cara memperbaikinya'],
    ];

    private const ALERT = [
        'TIP' => ['tone' => 'tip', 'label' => 'TIPS'],
        'NOTE' => ['tone' => 'info', 'label' => 'INFO'],
        'IMPORTANT' => ['tone' => 'info', 'label' => 'INFO'],
        'WARNING' => ['tone' => 'warning', 'label' => 'PERHATIAN'],
        'CAUTION' => ['tone' => 'warning', 'label' => 'PERHATIAN'],
    ];

    public static function folder(): string
    {
        return base_path('panduan');
    }

    /** @return Collection<int, array> Metadata semua artikel (tanpa isi), urut menurut grup lalu `urutan`. */
    public static function semua(): Collection
    {
        $folder = static::folder();
        if (! is_dir($folder)) {
            return collect();
        }

        $artikel = collect(glob($folder.'/*/*.md') ?: [])->map(function (string $berkas) {
            [$meta] = static::pisah((string) file_get_contents($berkas));
            $grup = basename(dirname($berkas));
            $nama = basename($berkas, '.md');

            return [
                'grup' => $grup,
                'slug' => preg_replace('/^\d+-/', '', $nama),
                'berkas' => $berkas,
                'judul' => $meta['judul'] ?? Str::headline($nama),
                'ringkas' => $meta['ringkas'] ?? '',
                'peran' => $meta['peran'] ?? Str::headline($grup),
                'urutan' => (int) ($meta['urutan'] ?? (int) $nama),
                'durasi' => $meta['durasi'] ?? null,
                'perangkat' => $meta['perangkat'] ?? null,
                'diperbarui' => static::tanggal($meta['diperbarui'] ?? null),
                'layar' => (array) ($meta['layar'] ?? []),
                'gambar' => $meta['gambar'] ?? null,
                'langkah' => preg_match_all('/^##\s+\d+\./m', (string) file_get_contents($berkas)),
            ];
        });

        $rank = fn (string $g) => ($i = array_search($g, self::URUTAN_GRUP, true)) === false ? count(self::URUTAN_GRUP) : $i;

        return $artikel->sort(fn ($a, $b) => [$rank($a['grup']), $a['grup'], $a['urutan'], $a['slug']] <=> [$rank($b['grup']), $b['grup'], $b['urutan'], $b['slug']])->values();
    }

    /** Menu samping: grup → artikel. */
    public static function menu(): Collection
    {
        return static::semua()->groupBy('grup')->map(fn (Collection $a, string $grup) => [
            'grup' => $grup,
            'label' => $a->first()['peran'],
            'artikel' => $a->values(),
        ])->values();
    }

    public static function cari(string $grup, string $slug): ?array
    {
        $meta = static::semua()->first(fn ($a) => $a['grup'] === $grup && $a['slug'] === $slug);
        if (! $meta) {
            return null;
        }
        [, $isi] = static::pisah((string) file_get_contents($meta['berkas']));

        return $meta + ['bagian' => static::bagian($isi, $meta)];
    }

    /** Indeks pencarian (dikirim ke browser): judul, ringkas, judul bagian, dan teks isi. */
    public static function indeks(): array
    {
        return static::semua()->map(function (array $a) {
            [, $isi] = static::pisah((string) file_get_contents($a['berkas']));
            $isi = preg_replace('/```gambar.*?```/s', '', $isi);
            preg_match_all('/^##\s+(.+)$/m', $isi, $h);

            return [
                'judul' => $a['judul'],
                'ringkas' => $a['ringkas'],
                'grup' => $a['peran'],
                'url' => route('panduan.artikel', [$a['grup'], $a['slug']]),
                'bagian' => $h[1],
                'teks' => trim(preg_replace('/\s+/', ' ', strip_tags((string) Str::markdown($isi)))),
            ];
        })->all();
    }

    public static function tanggal(?string $nilai): ?Carbon
    {
        try {
            return $nilai ? Carbon::parse($nilai) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0: array<string, string|array>, 1: string} frontmatter + isi */
    public static function pisah(string $teks): array
    {
        $teks = str_replace("\r\n", "\n", $teks);
        if (! preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $teks, $m)) {
            return [[], $teks];
        }
        $meta = [];
        foreach (explode("\n", $m[1]) as $baris) {
            if (preg_match('/^([\w-]+):\s*(.*)$/', $baris, $k)) {
                $nilai = trim($k[2]);
                $meta[$k[1]] = preg_match('/^\[(.*)\]$/', $nilai, $l)
                    ? array_values(array_filter(array_map(fn ($v) => trim($v, " \t\"'"), explode(',', $l[1])), 'strlen'))
                    : trim($nilai, " \t\"'");
            }
        }

        return [$meta, $m[2]];
    }

    // ── Titik masuk: tombol ? (desktop), tautan di layar, tautan di kabar

    /** Nama layar untuk rute + pengguna + query saat ini (mis. tab). Null bila layar itu tak punya nama. */
    public static function layarDari(User $user, string $rute, array $query = []): ?string
    {
        $tab = $query['tab'] ?? null;

        return match ($rute) {
            'usulan' => match ($user->role) {
                Role::Pengusul => 'usulan-saya',
                Role::Dishub => $tab === 'pasang' ? 'siap-dipasang' : 'usulan-masuk',
                Role::Bappeda => $tab === 'ditunda' ? 'pagu-ditunda' : 'keputusan',
                default => 'usulan-masuk',
            },
            'usulan.baru' => 'ajukan-usulan',
            'usulan.ubah' => 'revisi',
            'pemeliharaan' => $user->isPengusul() ? 'laporan-saya' : 'pemeliharaan',
            'lapor' => 'lapor-kerusakan',
            'laporan' => 'laporan',
            'pengaturan' => match ($tab) {
                'pengguna', 'instansi' => 'pengaturan-pengguna',
                'wilayah' => 'pengaturan-wilayah',
                default => 'pengaturan-ssh',
            },
            'profil', 'notifikasi' => $rute,
            default => null,
        };
    }

    /** Artikel yang menyebut layar ini di frontmatter (urut menurut menu). */
    public static function untukLayar(?string $layar, int $maks = 3): Collection
    {
        if (! $layar) {
            return collect();
        }

        return static::semua()->filter(fn ($a) => in_array($layar, $a['layar'], true))->take($maks)->values();
    }

    /** Data untuk menu bantuan: artikel untuk layar ini + keterangan "4 menit · 2 langkah". */
    public static function menuBantuan(?string $layar): array
    {
        return [
            'layar' => $layar,
            'nama' => $layar ? (self::LAYAR[$layar] ?? null) : null,
            'artikel' => static::untukLayar($layar)->map(fn ($a) => [
                'judul' => $a['judul'],
                'ket' => collect([$a['durasi'], $a['langkah'] ? $a['langkah'].' langkah' : null])->filter()->implode(' · '),
                'url' => route('panduan.artikel', [$a['grup'], $a['slug']]),
            ])->all(),
        ];
    }

    /** Tautan panduan untuk jenis kabar (mis. revisi), atau null bila tidak ada. */
    public static function tautanKabar(string $jenis): ?array
    {
        [$grup, $slug, $label] = self::UNTUK_KABAR[$jenis] ?? [null, null, null];
        $a = $grup ? static::semua()->first(fn ($x) => $x['grup'] === $grup && $x['slug'] === $slug) : null;

        return $a ? [
            'label' => $label,
            'durasi' => $a['durasi'],
            'judul' => $a['judul'],
            'url' => route('panduan.artikel', [$a['grup'], $a['slug']]),
        ] : null;
    }

    /** Artikel yang dituju (tanpa isi) — untuk tautan "Pertama kali? Lihat panduan N menit". */
    public static function ringkas(string $grup, string $slug): ?array
    {
        return static::semua()->first(fn ($a) => $a['grup'] === $grup && $a['slug'] === $slug);
    }

    /** Gambar pratinjau tautan (WhatsApp/OG): `gambar:` di frontmatter, kalau tidak gambar pertama di isi. */
    public static function gambarPratinjau(array $artikel): ?string
    {
        $nama = $artikel['gambar'] ?? null;
        if (! $nama) {
            foreach ($artikel['bagian'] as $b) {
                foreach ($b['blok'] as $x) {
                    if ($x['t'] === 'gambar') {
                        return $x['url'];
                    }
                }
            }

            return null;
        }

        return route('panduan.gambar', basename($nama));
    }

    // ── Isi artikel → bagian & blok

    /**
     * Bagian: intro | sebelum | langkah | selanjutnya | bagian. Tiap bagian punya `blok`:
     * p, daftar, gambar, callout, kode, h3.
     */
    private static function bagian(string $isi, array $artikel): array
    {
        $env = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $env->addExtension(new CommonMarkCoreExtension);
        $dokumen = (new MarkdownParser($env))->parse($isi);
        $renderer = new HtmlRenderer($env);

        $bagian = [['jenis' => 'intro', 'id' => 'intro', 'judul' => null, 'blok' => []]];
        foreach ($dokumen->children() as $node) {
            if ($node instanceof Heading && $node->getLevel() === 2) {
                $judul = trim(strip_tags($renderer->renderNodes($node->children())));
                $bagian[] = static::kepala($judul);

                continue;
            }
            $blok = static::blok($node, $renderer, $artikel);
            if ($blok) {
                $bagian[array_key_last($bagian)]['blok'][] = $blok;
            }
        }

        return array_values(array_filter($bagian, fn ($b) => $b['blok'] || $b['jenis'] !== 'intro'));
    }

    private static function kepala(string $judul): array
    {
        if (preg_match('/^(\d+)\.\s*(.+)$/u', $judul, $m)) {
            return ['jenis' => 'langkah', 'id' => 'langkah-'.$m[1], 'nomor' => (int) $m[1], 'judul' => $m[2], 'blok' => []];
        }
        $kunci = Str::lower($judul);

        return [
            'jenis' => match ($kunci) {
                'sebelum mulai' => 'sebelum',
                'selanjutnya' => 'selanjutnya',
                default => 'bagian',
            },
            'id' => Str::slug($judul) ?: 'bagian',
            'judul' => $judul,
            'blok' => [],
        ];
    }

    private static function blok(Node $node, HtmlRenderer $r, array $artikel): ?array
    {
        if ($node instanceof Paragraph) {
            $gambar = $node->firstChild();
            if ($gambar instanceof Image && $gambar === $node->lastChild()) {
                return static::gambar($gambar->getUrl(), $gambar->firstChild() ? trim(strip_tags($r->renderNodes($gambar->children()))) : '', []);
            }

            return ['t' => 'p', 'html' => static::inline($node, $r, $artikel)];
        }
        if ($node instanceof FencedCode && trim($node->getInfo() ?? '') === 'gambar') {
            return static::gambarDariBlok($node->getLiteral());
        }
        if ($node instanceof FencedCode || $node instanceof IndentedCode) {
            return ['t' => 'kode', 'teks' => rtrim($node->getLiteral())];
        }
        if ($node instanceof ListBlock) {
            $butir = [];
            foreach ($node->children() as $item) {
                $butir[] = [
                    'html' => static::inline($item, $r, $artikel),
                    'tautan' => static::tautanButir($item, $artikel),
                ];
            }

            return ['t' => 'daftar', 'urut' => $node->getListData()->type === 'ordered', 'butir' => $butir];
        }
        if ($node instanceof BlockQuote) {
            return static::callout($node, $r, $artikel);
        }
        if ($node instanceof Heading) {
            return ['t' => 'h3', 'html' => trim($r->renderNodes($node->children()))];
        }

        return null;
    }

    /** HTML sebaris dari paragraf / butir daftar; tautan `.md` diarahkan ke halaman panduan. */
    private static function inline(Node $node, HtmlRenderer $r, array $artikel): string
    {
        foreach ($node->iterator() as $n) {
            if ($n instanceof Link) {
                $n->setUrl(static::url($n->getUrl(), $artikel));
            }
        }

        return trim($r->renderNodes($node->children()));
    }

    private static function callout(BlockQuote $q, HtmlRenderer $r, array $artikel): array
    {
        $paragraf = [];
        foreach ($q->children() as $anak) {
            $paragraf[] = $anak instanceof Paragraph ? static::inline($anak, $r, $artikel) : trim($r->renderNode($anak));
        }
        $jenis = 'NOTE';
        if ($paragraf && preg_match('/^\s*\[!(TIP|NOTE|IMPORTANT|WARNING|CAUTION)\]\s*(?:<br\s*\/?>)?\s*/i', $paragraf[0], $m)) {
            $jenis = strtoupper($m[1]);
            $paragraf[0] = substr($paragraf[0], strlen($m[0]));
        }

        return ['t' => 'callout'] + self::ALERT[$jenis] + ['paragraf' => array_values(array_filter($paragraf, 'strlen'))];
    }

    /** Blok ```gambar: src, keterangan, penanda (daftar [x%, y%, teks]). */
    private static function gambarDariBlok(string $isi): array
    {
        $src = $ket = '';
        $penanda = [];
        foreach (preg_split('/\R/', $isi) as $baris) {
            if (preg_match('/^src:\s*(.+)$/', $baris, $m)) {
                $src = trim($m[1]);
            } elseif (preg_match('/^keterangan:\s*(.+)$/', $baris, $m)) {
                $ket = trim($m[1], " \t\"'");
            } elseif (preg_match('/^\s*-\s*\[\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*["\']?(.*?)["\']?\s*\]\s*(?:#.*)?$/', $baris, $m)) {
                $penanda[] = ['x' => (float) $m[1], 'y' => (float) $m[2], 'teks' => $m[3]];
            }
        }

        return static::gambar($src, $ket, $penanda);
    }

    private static function gambar(string $src, string $ket, array $penanda): array
    {
        $nama = basename($src);
        $ukuran = @getimagesize(static::folder().'/gambar/'.$nama) ?: [0, 0];

        return [
            't' => 'gambar',
            // ?v= = waktu ubah berkas, supaya screenshot yang diperbarui tidak tertahan di cache browser
            'url' => route('panduan.gambar', $nama).'?v='.(@filemtime(static::folder().'/gambar/'.$nama) ?: 0),
            'ket' => $ket,
            'penanda' => $penanda,
            'lebar' => $ukuran[0],
            'tinggi' => $ukuran[1],
            // Screenshot layar lebar (desktop) tampil selebar kolom; screenshot HP tampil sebagai bingkai ponsel.
            'lebarLayar' => $ukuran[0] > $ukuran[1],
        ];
    }

    // ── Tautan antar artikel

    /** Tautan relatif `./02-pantau.md` / `../dishub/01-x.md` → URL halaman. */
    public static function url(string $href, array $artikel): string
    {
        $tujuan = static::cariDariTautan($href, $artikel);

        return $tujuan ? route('panduan.artikel', [$tujuan['grup'], $tujuan['slug']]) : $href;
    }

    public static function cariDariTautan(string $href, array $artikel): ?array
    {
        if (! preg_match('/\.md(?:#.*)?$/', $href)) {
            return null;
        }
        $jalur = preg_replace('/\.md(#.*)?$/', '', $href);
        $bagian = array_values(array_filter(explode('/', $jalur), fn ($b) => $b !== '' && $b !== '.' && $b !== '..'));
        $slug = preg_replace('/^\d+-/', '', array_pop($bagian) ?? '');
        $grup = array_pop($bagian) ?? $artikel['grup'];

        return static::semua()->first(fn ($a) => $a['grup'] === $grup && $a['slug'] === $slug);
    }

    /** Butir daftar yang isinya satu tautan ke artikel lain → metadata artikel itu. */
    private static function tautanButir(Node $item, array $artikel): ?array
    {
        $p = $item->firstChild();
        $tautan = $p?->firstChild();
        if ($tautan instanceof Link && $tautan === $p->lastChild()) {
            // URL sudah diubah oleh inline(); cari lewat rute yang dihasilkan.
            return static::semua()->first(fn ($a) => route('panduan.artikel', [$a['grup'], $a['slug']]) === $tautan->getUrl());
        }

        return null;
    }
}
