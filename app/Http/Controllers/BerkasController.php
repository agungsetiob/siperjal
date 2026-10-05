<?php

namespace App\Http\Controllers;

use App\Models\Usulan;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class BerkasController extends Controller
{
    public function proposal(Usulan $usulan)
    {
        abort_unless(Usulan::terlihatOleh(auth()->user())->whereKey($usulan->id)->exists(), 403);
        abort_unless($usulan->proposal_path && Storage::disk('local')->exists($usulan->proposal_path), 404);

        return Storage::disk('local')->response($usulan->proposal_path, "Proposal {$usulan->nomor}.pdf");
    }

    /** Pratinjau proposal otomatis dari isian draft (sebelum dikirim). */
    public function pratinjau(Usulan $usulan)
    {
        $user = auth()->user();
        $milik = $user->instansi_id ? $usulan->instansi_id === $user->instansi_id : $usulan->user_id === $user->id;
        abort_unless($milik, 403);

        $usulan->load('items.jenis', 'desa', 'kecamatan', 'user', 'instansi', 'foto');

        return Pdf::loadView('pdf.proposal', ['usulan' => $usulan])->stream('Pratinjau proposal.pdf');
    }
}
