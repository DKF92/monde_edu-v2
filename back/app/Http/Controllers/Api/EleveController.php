<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Eleve;
use Illuminate\Http\Request;

class EleveController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('eleves.voir'), 403);

        $eleves = Eleve::query()
            ->when($request->string('recherche')->isNotEmpty(), function ($query) use ($request) {
                $recherche = $request->string('recherche')->value();
                $query->where(function ($q) use ($recherche) {
                    $q->where('nom', 'like', "%{$recherche}%")
                        ->orWhere('prenoms', 'like', "%{$recherche}%")
                        ->orWhere('matricule', 'like', "%{$recherche}%");
                });
            })
            ->orderBy('nom')
            ->paginate($request->integer('par_page', 20));

        return response()->json($eleves);
    }

    public function show(Request $request, Eleve $eleve)
    {
        abort_unless($request->user()->can('eleves.voir'), 403);

        return response()->json(
            $eleve->load(['tuteurs', 'inscriptions.classe.niveau'])
        );
    }
}
