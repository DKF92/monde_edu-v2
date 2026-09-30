<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Depense;
use App\Models\Etablissement;
use App\Models\Reglement;
use App\Support\BilanCaisse;
use App\Support\Document;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Depenses (V1 pages/Depense) :
 * - depenses.gerer : enregistrer ; sans depenses.voir, on ne voit que les siennes
 *   (V1 : le caissier ne liste que ses sorties) ;
 * - depenses.voir : toutes les depenses, le point mensuel (V1 "Point salaires /
 *   autres depenses") et le solde de caisse (V1 : encaissements - depenses) ;
 * - depenses.modifier : corriger / supprimer avec motif (historise).
 */
class DepenseController extends Controller
{
    // ------------------------------------------------------------ Liste

    public function index(Request $request, TenantContext $tenant)
    {
        [$requete, $filtres] = $this->requete($request, $tenant);

        $parCategorie = (clone $requete)->reorder()->selectRaw('categorie, SUM(montant) AS total, COUNT(*) AS nombre')
            ->groupBy('categorie')->get()->keyBy('categorie');
        $page = (clone $requete)->with('payeur:id,name,prenoms')
            ->paginate(min(max($request->integer('par_page', 10), 1), 200));

        return response()->json([
            'data' => collect($page->items())->map(fn (Depense $d) => $this->presenter($d))->values(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'par_page' => $page->perPage(),
            'du' => $filtres['du'],
            'au' => $filtres['au'],
            'mes_depenses' => ! $request->user()->can('depenses.voir'),
            'totaux' => [
                'nombre' => (int) $parCategorie->sum('nombre'),
                'montant' => (int) $parCategorie->sum('total'),
                'par_categorie' => collect(Depense::CATEGORIES)->map(fn ($libelle, $cle) => [
                    'categorie' => $cle, 'libelle' => $libelle, 'montant' => (int) ($parCategorie[$cle]->total ?? 0),
                ])->values(),
            ],
            'categories' => collect(Depense::CATEGORIES)->map(fn ($l, $c) => ['valeur' => $c, 'libelle' => $l])->values(),
            'payeurs' => $request->user()->can('depenses.voir')
                ? Depense::where('annee_scolaire_id', $this->anneeId($tenant))->with('payeur:id,name,prenoms')->get()
                    ->pluck('payeur')->filter()->unique('id')->map(fn ($u) => ['id' => $u->id, 'nom' => trim($u->name.' '.$u->prenoms)])->values()
                : [],
        ]);
    }

    /** Liste imprimable (PDF, Word, Excel) avec les memes filtres. */
    public function document(Request $request, TenantContext $tenant)
    {
        [$requete, $filtres] = $this->requete($request, $tenant);
        $depenses = $requete->with('payeur:id,name,prenoms')->get()->map(fn (Depense $d) => $this->presenter($d));
        $annee = AnneeScolaire::find($this->anneeId($tenant));

        return Document::repondre('pdf.depenses', Document::entete(Etablissement::findOrFail($tenant->id())) + [
            'titreDocument' => 'DÉPENSES',
            'annee' => $annee?->libelle,
            'titre' => 'Dépenses '.$this->libellePeriode($filtres, $annee),
            'depenses' => $depenses,
            'total' => (int) $depenses->sum('montant'),
            'parCategorie' => $depenses->groupBy('categorie')->map(fn ($g, $c) => ['libelle' => Depense::CATEGORIES[$c] ?? $c, 'montant' => (int) $g->sum('montant'), 'nombre' => $g->count()])->values(),
            'genere_le' => now(),
            'edite_par' => trim($request->user()->name.' '.$request->user()->prenoms),
        ], 'depenses', $request->query('format'), 'landscape');
    }

    // ------------------------------------------------------------ Saisie

    public function store(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('depenses.gerer'), 403);

        $data = $this->valider($request);
        $anneeId = $this->anneeId($tenant);
        abort_unless($anneeId, 422, 'Aucune année scolaire active.');

        $depense = DB::transaction(function () use ($data, $anneeId, $request) {
            return Depense::create($this->champs($data) + [
                'annee_scolaire_id' => $anneeId,
                'numero' => $this->numero($anneeId),
                'payeur_id' => $request->user()->id,
            ]);
        });
        $this->enregistrerJustificatif($request, $depense);

        return response()->json(['data' => $this->presenter($depense->fresh('payeur'))], 201);
    }

    public function show(Request $request, int $id)
    {
        $depense = $this->trouver($request, $id);

        return response()->json(['data' => $this->presenter($depense->load('payeur')) + [
            'historique' => DB::table('depenses_historique as h')->leftJoin('users as u', 'u.id', '=', 'h.user_id')
                ->where('h.depense_id', $depense->id)->orderByDesc('h.id')
                ->get(['h.action', 'h.motif', 'h.avant', 'h.apres', 'h.created_at', 'u.name', 'u.prenoms'])
                ->map(fn ($h) => [
                    'action' => $h->action,
                    'motif' => $h->motif,
                    'avant' => json_decode($h->avant, true),
                    'apres' => $h->apres ? json_decode($h->apres, true) : null,
                    'date' => $h->created_at,
                    'par' => trim(($h->name ?? '').' '.($h->prenoms ?? '')),
                ])->values(),
        ]]);
    }

    /** Corrige une depense (motif obligatoire) ; POST pour accepter un nouveau justificatif. */
    public function update(Request $request, int $id)
    {
        abort_unless($request->user()->can('depenses.modifier'), 403);

        $depense = $this->trouver($request, $id);
        $data = $this->valider($request, true);
        $avant = $this->instantane($depense);

        DB::transaction(fn () => $depense->update($this->champs($data)));
        if ($request->boolean('retirer_justificatif') && $depense->piece_justificative_path) {
            $depense->update(['piece_justificative_path' => null]);
        }
        $this->enregistrerJustificatif($request, $depense);
        $this->historiser($depense, 'modification', $data['motif'], $avant, $this->instantane($depense->fresh()), $request->user()->id);

        return response()->json(['data' => $this->presenter($depense->fresh('payeur'))]);
    }

    public function destroy(Request $request, int $id)
    {
        abort_unless($request->user()->can('depenses.modifier'), 403);

        $depense = $this->trouver($request, $id);
        $data = $request->validate(['motif' => ['required', 'string', 'min:3', 'max:500']], [
            'motif.required' => 'Le motif de la suppression est obligatoire.',
            'motif.min' => 'Précisez le motif (3 caractères au moins).',
        ]);
        DB::transaction(function () use ($depense, $data, $request) {
            // Le justificatif est conserve : l'historique garde son chemin.
            $this->historiser($depense, 'suppression', $data['motif'], $this->instantane($depense), null, $request->user()->id);
            $depense->delete();
        });

        return response()->noContent();
    }

    // ------------------------------------------------------------ Point et solde de caisse

    /**
     * Point des depenses (V1 depense.php : salaires / autres par mois) et solde
     * de caisse (V1 solde_caisse : encaissements - depenses) sur une periode.
     */
    public function point(Request $request, TenantContext $tenant)
    {
        return response()->json(['data' => $this->donneesPoint($request, $tenant)]);
    }

    public function pointDocument(Request $request, TenantContext $tenant)
    {
        $point = $this->donneesPoint($request, $tenant);

        return Document::repondre('pdf.point-depenses', Document::entete(Etablissement::findOrFail($tenant->id())) + [
            'titreDocument' => 'POINT DE CAISSE',
            'annee' => $point['annee'],
            'p' => $point,
            'genere_le' => now(),
            'edite_par' => trim($request->user()->name.' '.$request->user()->prenoms),
        ], 'point-depenses', $request->query('format'));
    }

    private function donneesPoint(Request $request, TenantContext $tenant): array
    {
        abort_unless($request->user()->can('depenses.voir'), 403);

        $filtres = $request->validate([
            'periode' => ['nullable', Rule::in(['jour', 'dates', 'mois', 'annee'])],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'mois' => ['nullable', 'string'],
        ]);
        $filtres['periode'] ??= 'annee';
        $annee = AnneeScolaire::find($this->anneeId($tenant));
        abort_unless($annee, 422, 'Aucune année scolaire active.');
        $periode = BilanCaisse::periode($filtres, $annee);

        $dansPeriode = fn ($q, string $colonne) => $q
            ->when($periode['du'], fn ($x, $d) => $x->whereDate($colonne, '>=', $d))
            ->when($periode['au'], fn ($x, $d) => $x->whereDate($colonne, '<=', $d));
        $encaissements = $dansPeriode(Reglement::where('annee_scolaire_id', $annee->id), 'date_paiement')
            ->selectRaw("DATE_FORMAT(date_paiement, '%Y-%m') AS mois, SUM(montant_total) AS total")->groupBy('mois')->pluck('total', 'mois');
        $depenses = $dansPeriode(Depense::where('annee_scolaire_id', $annee->id), 'date_depense')
            ->selectRaw("DATE_FORMAT(date_depense, '%Y-%m') AS mois, categorie, SUM(montant) AS total")->groupBy('mois', 'categorie')->get();

        // Mois de la periode (annee scolaire : tous ses mois).
        $mois = collect(BilanCaisse::moisDeLAnnee($annee))
            ->filter(fn ($m) => (! $periode['du'] || $m['valeur'] >= substr($periode['du'], 0, 7)) && (! $periode['au'] || $m['valeur'] <= substr($periode['au'], 0, 7)));
        $lignes = $mois->map(function ($m) use ($encaissements, $depenses) {
            $duMois = $depenses->where('mois', $m['valeur']);
            $salaires = (int) $duMois->where('categorie', 'salaires')->sum('total');
            $total = (int) $duMois->sum('total');
            $entrees = (int) ($encaissements[$m['valeur']] ?? 0);

            return ['mois' => $m['libelle'], 'encaissements' => $entrees, 'salaires' => $salaires, 'autres' => $total - $salaires, 'depenses' => $total, 'solde' => $entrees - $total];
        })->values();

        $totalEntrees = (int) $encaissements->sum();
        $totalSorties = (int) $depenses->sum('total');

        return [
            'periode' => $periode,
            'titre' => 'Point de caisse · '.$periode['libelle'],
            'annee' => $annee->libelle,
            'mois_disponibles' => BilanCaisse::moisDeLAnnee($annee),
            'encaissements' => $totalEntrees,
            'depenses' => $totalSorties,
            'solde' => $totalEntrees - $totalSorties,
            'par_categorie' => collect(Depense::CATEGORIES)->map(fn ($libelle, $cle) => [
                'categorie' => $cle, 'libelle' => $libelle, 'montant' => (int) $depenses->where('categorie', $cle)->sum('total'),
            ])->values(),
            'par_mois' => $lignes,
        ];
    }

    // ------------------------------------------------------------ Outils

    /** @return array{0: Builder, 1: array} */
    private function requete(Request $request, TenantContext $tenant): array
    {
        abort_unless($request->user()->can('depenses.gerer') || $request->user()->can('depenses.voir'), 403);

        $f = $request->validate([
            'periode' => ['nullable', Rule::in(['jour', 'semaine', 'mois', 'annee', 'dates'])],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'categorie' => ['nullable', Rule::in(array_keys(Depense::CATEGORIES))],
            'mode' => ['nullable', Rule::in(array_keys(Depense::MODES))],
            'payeur_id' => ['nullable', 'integer'],
            'recherche' => ['nullable', 'string', 'max:100'],
        ]);
        [$f['du'], $f['au']] = match ($f['periode'] ?? 'annee') {
            'jour' => [today()->toDateString(), today()->toDateString()],
            'semaine' => [today()->subDays(6)->toDateString(), today()->toDateString()],
            'mois' => [today()->startOfMonth()->toDateString(), today()->toDateString()],
            'dates' => [$f['du'] ?? null, $f['au'] ?? null],
            default => [null, null],
        };
        // Sans "Voir les depenses" : seulement les siennes (V1 payeur_dep = login).
        if (! $request->user()->can('depenses.voir')) {
            $f['payeur_id'] = $request->user()->id;
        }
        $texte = trim($f['recherche'] ?? '');

        $requete = Depense::where('annee_scolaire_id', $this->anneeId($tenant))
            ->when($f['du'], fn (Builder $q, $d) => $q->whereDate('date_depense', '>=', $d))
            ->when($f['au'], fn (Builder $q, $d) => $q->whereDate('date_depense', '<=', $d))
            ->when($f['categorie'] ?? null, fn (Builder $q, $c) => $q->where('categorie', $c))
            ->when($f['mode'] ?? null, fn (Builder $q, $m) => $q->where('mode_paiement', $m))
            ->when($f['payeur_id'] ?? null, fn (Builder $q, $p) => $q->where('payeur_id', $p))
            ->when($texte !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('libelle', 'like', "%{$texte}%")
                ->orWhere('beneficiaire', 'like', "%{$texte}%")->orWhere('numero', 'like', "%{$texte}%")
                ->orWhere('piece_comptable', 'like', "%{$texte}%")))
            ->orderByDesc('date_depense')->orderByDesc('id');

        return [$requete, $f];
    }

    private function valider(Request $request, bool $modification = false): array
    {
        return $request->validate(array_merge($modification ? ['motif' => ['required', 'string', 'min:3', 'max:500']] : [], [
            'date_depense' => ['required', 'date', 'before_or_equal:today'],
            'categorie' => ['required', Rule::in(array_keys(Depense::CATEGORIES))],
            'libelle' => ['required', 'string', 'max:300'],
            'montant' => ['required', 'integer', 'min:1'],
            'mode_paiement' => ['required', Rule::in(array_keys(Depense::MODES))],
            'beneficiaire' => ['nullable', 'string', 'max:150'],
            'piece_comptable' => ['nullable', 'string', 'max:100'],
            'justificatif' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]), [
            'motif.required' => 'Le motif de la modification est obligatoire.',
            'motif.min' => 'Précisez le motif (3 caractères au moins).',
            'date_depense.before_or_equal' => 'La date ne peut pas être dans le futur.',
            'libelle.required' => 'Précisez l\'objet de la dépense.',
            'montant.min' => 'Le montant doit être positif.',
            'justificatif.mimes' => 'Le justificatif doit être une image ou un PDF.',
            'justificatif.max' => 'Le justificatif ne doit pas dépasser 5 Mo.',
        ]);
    }

    private function champs(array $data): array
    {
        return [
            'date_depense' => $data['date_depense'],
            'categorie' => $data['categorie'],
            'libelle' => trim($data['libelle']),
            'montant' => (int) $data['montant'],
            'mode_paiement' => $data['mode_paiement'],
            'beneficiaire' => $data['beneficiaire'] ?? null,
            'piece_comptable' => $data['piece_comptable'] ?? null,
        ];
    }

    private function enregistrerJustificatif(Request $request, Depense $depense): void
    {
        if ($request->hasFile('justificatif')) {
            $chemin = $request->file('justificatif')->store('depenses/'.$depense->etablissement_id, 'public');
            $depense->update(['piece_justificative_path' => $chemin]);
        }
    }

    /** "D2627-00001" : code de l'annee + ordre dans l'etablissement. */
    private function numero(int $anneeId): string
    {
        $libelle = AnneeScolaire::find($anneeId)?->libelle ?? '';
        $prefixe = 'D'.(preg_match('/^\d{2}(\d{2})-\d{2}(\d{2})$/', $libelle, $m) ? $m[1].$m[2] : $anneeId);
        $dernier = Depense::where('numero', 'like', $prefixe.'-%')->lockForUpdate()->max('numero');

        return $prefixe.'-'.str_pad((string) (($dernier ? (int) substr($dernier, strlen($prefixe) + 1) : 0) + 1), 5, '0', STR_PAD_LEFT);
    }

    /** Une depense de l'annee ; sans "Voir les depenses", seulement les siennes. */
    private function trouver(Request $request, int $id): Depense
    {
        abort_unless($request->user()->can('depenses.gerer') || $request->user()->can('depenses.voir'), 403);
        $depense = Depense::findOrFail($id);
        abort_if(! $request->user()->can('depenses.voir') && $depense->payeur_id !== $request->user()->id, 403);

        return $depense;
    }

    private function instantane(Depense $d): array
    {
        return [
            'numero' => $d->numero,
            'date_depense' => $d->date_depense?->toDateString(),
            'categorie' => $d->categorie,
            'libelle' => $d->libelle,
            'montant' => (int) $d->montant,
            'mode_paiement' => $d->mode_paiement,
            'beneficiaire' => $d->beneficiaire,
            'piece_comptable' => $d->piece_comptable,
            'piece_justificative_path' => $d->piece_justificative_path,
        ];
    }

    private function historiser(Depense $d, string $action, string $motif, array $avant, ?array $apres, int $userId): void
    {
        DB::table('depenses_historique')->insert([
            'etablissement_id' => $d->etablissement_id,
            'depense_id' => $d->id,
            'numero' => $d->numero,
            'action' => $action,
            'motif' => mb_substr(trim($motif), 0, 500),
            'avant' => json_encode($avant, JSON_UNESCAPED_UNICODE),
            'apres' => $apres ? json_encode($apres, JSON_UNESCAPED_UNICODE) : null,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function presenter(Depense $d): array
    {
        return [
            'id' => $d->id,
            'numero' => $d->numero,
            'date' => $d->date_depense?->toDateString(),
            'categorie' => $d->categorie,
            'categorie_libelle' => Depense::CATEGORIES[$d->categorie] ?? $d->categorie,
            'libelle' => $d->libelle,
            'montant' => (int) $d->montant,
            'mode' => $d->mode_paiement,
            'beneficiaire' => $d->beneficiaire,
            'piece_comptable' => $d->piece_comptable,
            'justificatif_url' => $d->piece_justificative_path ? Storage::disk('public')->url($d->piece_justificative_path) : null,
            'justificatif_pdf' => $d->piece_justificative_path ? str_ends_with(strtolower($d->piece_justificative_path), '.pdf') : false,
            'payeur_id' => $d->payeur_id,
            'payeur' => $d->payeur ? trim($d->payeur->name.' '.$d->payeur->prenoms) : null,
            'saisie_le' => $d->created_at?->toDateTimeString(),
        ];
    }

    private function libellePeriode(array $f, ?AnneeScolaire $annee): string
    {
        $fr = fn ($d) => CarbonImmutable::parse($d)->format('d/m/Y');
        if (! $f['du']) {
            return 'de l\'année scolaire '.($annee?->libelle ?? '');
        }

        return $f['du'] === $f['au'] ? 'du '.$fr($f['du']) : 'du '.$fr($f['du']).' au '.$fr($f['au']);
    }

    private function anneeId(TenantContext $tenant): ?int
    {
        return $tenant->anneeScolaireId() ?? AnneeScolaire::where('is_active', true)->value('id');
    }
}
