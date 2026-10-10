<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Banque;
use App\Models\Entree;
use App\Models\Etablissement;
use App\Models\User;
use App\Support\Document;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Entrees (pendant des depenses, nouveaute V2) :
 * - entrees caisse = paiements des eleves : pas de saisie, seulement le total
 *   de chaque caissier sur la periode (le detail est dans Paiements) ;
 * - entrees banque (table entrees) : la banque tient lieu de beneficiaire.
 * Droits : entrees.gerer (enregistrer ; sans entrees.voir, seulement les
 * siennes), entrees.voir (tout, caisse comprise), entrees.modifier (motif).
 */
class EntreeController extends Controller
{
    // ------------------------------------------------------------ Liste

    public function index(Request $request, TenantContext $tenant)
    {
        [$requete, $filtres] = $this->requete($request, $tenant);
        $voirTout = $request->user()->can('entrees.voir');

        $parCategorie = (clone $requete)->reorder()->selectRaw('categorie, SUM(montant) AS total, COUNT(*) AS nombre')->groupBy('categorie')->get()->keyBy('categorie');
        $page = (clone $requete)->with(['saisiPar:id,name,prenoms', 'banque', 'versePar:id,name,prenoms'])
            ->paginate(min(max($request->integer('par_page', 10), 1), 200));

        return response()->json([
            'data' => collect($page->items())->map(fn (Entree $e) => $this->presenter($e))->values(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'par_page' => $page->perPage(),
            'du' => $filtres['du'],
            'au' => $filtres['au'],
            'mes_entrees' => ! $voirTout,
            'totaux' => [
                'nombre' => (int) $parCategorie->sum('nombre'),
                'montant' => (int) $parCategorie->sum('total'),
                // Versements de la caisse : transfert, deja compte dans les paiements.
                'versements_caisse' => (int) ($parCategorie['versement_caisse']->total ?? 0),
            ],
            // Entrees caisse : total de chaque caissier (pas de liste, voir Paiements).
            'caisse' => $voirTout ? $this->caisse($tenant, $filtres) : null,
            'categories' => collect(Entree::CATEGORIES)->map(fn ($l, $c) => ['valeur' => $c, 'libelle' => $l])->values(),
            'banques' => Banque::orderBy('nom')->get(['id', 'nom', 'sigle'])->map(fn (Banque $b) => ['id' => $b->id, 'libelle' => $this->nomBanque($b)])->values(),
            'saisies' => $voirTout
                ? User::whereIn('id', Entree::where('annee_scolaire_id', $this->anneeId($tenant))->whereNotNull('saisi_par_id')->distinct()->select('saisi_par_id'))
                    ->orderBy('name')->get(['id', 'name', 'prenoms'])->map(fn ($u) => ['id' => $u->id, 'nom' => trim($u->name.' '.$u->prenoms)])->values()
                : [],
        ]);
    }

    /** Liste imprimable : entrees caisse par caissier, puis entrees banque et leurs bilans. */
    public function document(Request $request, TenantContext $tenant)
    {
        [$requete, $filtres] = $this->requete($request, $tenant);
        $entrees = $requete->with(['saisiPar:id,name,prenoms', 'banque', 'versePar:id,name,prenoms'])->get()->map(fn (Entree $e) => $this->presenter($e));
        $annee = AnneeScolaire::find($this->anneeId($tenant));
        // La caisse n'a de sens que sans filtre propre a la banque.
        $avecCaisse = $request->user()->can('entrees.voir') && ! ($filtres['categorie'] ?? null) && ! ($filtres['banque_id'] ?? null)
            && ! ($filtres['saisi_par_id'] ?? null) && trim($filtres['recherche'] ?? '') === '';

        return Document::repondre('pdf.entrees', Document::entete(Etablissement::findOrFail($tenant->id())) + [
            'titreDocument' => 'ENTRÉES',
            'annee' => $annee?->libelle,
            'titre' => 'Entrées '.$this->libellePeriode($filtres, $annee),
            'caisse' => $avecCaisse ? $this->caisse($tenant, $filtres) : null,
            'entrees' => $entrees,
            'total' => (int) $entrees->sum('montant'),
            'versements' => (int) $entrees->where('categorie', 'versement_caisse')->sum('montant'),
            'bilans' => [
                'Bilan par banque' => $this->bilan($entrees, fn ($e) => $e['banque'] ?? '—'),
                'Bilan par catégorie' => $this->bilan($entrees, fn ($e) => $e['categorie_libelle']),
                'Bilan par personne ayant saisi' => $this->bilan($entrees, fn ($e) => $e['saisi_par'] ?? '—'),
            ],
            'genere_le' => now(),
            'edite_par' => trim($request->user()->name.' '.$request->user()->prenoms),
        ], 'entrees', $request->query('format'), 'landscape');
    }

    // ------------------------------------------------------------ Saisie

    /** Listes du formulaire : banques actives et personnel habilite aux operations bancaires. */
    public function options(Request $request)
    {
        abort_unless($request->user()->can('entrees.gerer') || $request->user()->can('entrees.modifier'), 403);

        return response()->json([
            'banques' => Banque::where('is_active', true)->orderBy('nom')->get()
                ->map(fn (Banque $b) => ['id' => $b->id, 'libelle' => $this->nomBanque($b).($b->numero_compte ? ' · '.$b->numero_compte : '')])->values(),
            'versements' => $this->habilites()->values(),
            'categories' => collect(Entree::CATEGORIES)->map(fn ($l, $c) => ['valeur' => $c, 'libelle' => $l])->values(),
        ]);
    }

    public function store(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('entrees.gerer'), 403);

        $data = $this->valider($request);
        $anneeId = $this->anneeId($tenant);
        abort_unless($anneeId, 422, 'Aucune année scolaire active.');

        $entree = DB::transaction(fn () => Entree::create($this->champs($data) + [
            'annee_scolaire_id' => $anneeId,
            'numero' => $this->numero($anneeId),
            'saisi_par_id' => $request->user()->id,
        ]));
        $this->enregistrerJustificatif($request, $entree);

        return response()->json(['data' => $this->presenter($entree->fresh(['saisiPar', 'banque', 'versePar']))], 201);
    }

    public function show(Request $request, int $id)
    {
        $entree = $this->trouver($request, $id);

        return response()->json(['data' => $this->presenter($entree->load(['saisiPar', 'banque', 'versePar'])) + [
            'historique' => DB::table('entrees_historique as h')->leftJoin('users as u', 'u.id', '=', 'h.user_id')
                ->where('h.entree_id', $entree->id)->orderByDesc('h.id')
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

    /** Corrige une entree (motif obligatoire) ; POST pour accepter un nouveau justificatif. */
    public function update(Request $request, int $id)
    {
        abort_unless($request->user()->can('entrees.modifier'), 403);

        $entree = $this->trouver($request, $id);
        $data = $this->valider($request, true);
        $avant = $this->instantane($entree);

        DB::transaction(fn () => $entree->update($this->champs($data)));
        if ($request->boolean('retirer_justificatif') && $entree->piece_justificative_path) {
            $entree->update(['piece_justificative_path' => null]);
        }
        $this->enregistrerJustificatif($request, $entree);
        $this->historiser($entree, 'modification', $data['motif'], $avant, $this->instantane($entree->fresh()), $request->user()->id);

        return response()->json(['data' => $this->presenter($entree->fresh(['saisiPar', 'banque', 'versePar']))]);
    }

    public function destroy(Request $request, int $id)
    {
        abort_unless($request->user()->can('entrees.modifier'), 403);

        $entree = $this->trouver($request, $id);
        $data = $request->validate(['motif' => ['required', 'string', 'min:3', 'max:500']], [
            'motif.required' => 'Le motif de la suppression est obligatoire.',
            'motif.min' => 'Précisez le motif (3 caractères au moins).',
        ]);
        DB::transaction(function () use ($entree, $data, $request) {
            $this->historiser($entree, 'suppression', $data['motif'], $this->instantane($entree), null, $request->user()->id);
            $entree->delete();
        });

        return response()->noContent();
    }

    // ------------------------------------------------------------ Outils

    /** @return array{0: Builder, 1: array} */
    private function requete(Request $request, TenantContext $tenant): array
    {
        abort_unless($request->user()->can('entrees.gerer') || $request->user()->can('entrees.voir'), 403);

        $f = $request->validate([
            'periode' => ['nullable', Rule::in(['jour', 'semaine', 'mois', 'annee', 'dates'])],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'categorie' => ['nullable', Rule::in(array_keys(Entree::CATEGORIES))],
            'mode' => ['nullable', Rule::in(array_keys(Entree::MODES))],
            'banque_id' => ['nullable', 'integer'],
            'saisi_par_id' => ['nullable', 'integer'],
            'recherche' => ['nullable', 'string', 'max:100'],
        ]);
        [$f['du'], $f['au']] = match ($f['periode'] ?? 'annee') {
            'jour' => [today()->toDateString(), today()->toDateString()],
            'semaine' => [today()->subDays(6)->toDateString(), today()->toDateString()],
            'mois' => [today()->startOfMonth()->toDateString(), today()->toDateString()],
            'dates' => [$f['du'] ?? null, $f['au'] ?? null],
            default => [null, null],
        };
        // Sans "Voir les entrees" : seulement les siennes.
        if (! $request->user()->can('entrees.voir')) {
            $f['saisi_par_id'] = $request->user()->id;
        }
        $texte = trim($f['recherche'] ?? '');

        $requete = Entree::where('annee_scolaire_id', $this->anneeId($tenant))
            ->when($f['du'], fn (Builder $q, $d) => $q->whereDate('date_entree', '>=', $d))
            ->when($f['au'], fn (Builder $q, $d) => $q->whereDate('date_entree', '<=', $d))
            ->when($f['categorie'] ?? null, fn (Builder $q, $c) => $q->where('categorie', $c))
            ->when($f['mode'] ?? null, fn (Builder $q, $m) => $q->where('mode_paiement', $m))
            ->when($f['banque_id'] ?? null, fn (Builder $q, $b) => $q->where('banque_id', $b))
            ->when($f['saisi_par_id'] ?? null, fn (Builder $q, $p) => $q->where('saisi_par_id', $p))
            ->when($texte !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('libelle', 'like', "%{$texte}%")
                ->orWhere('provenance', 'like', "%{$texte}%")->orWhere('numero', 'like', "%{$texte}%")
                ->orWhere('piece_comptable', 'like', "%{$texte}%")))
            ->orderByDesc('date_entree')->orderByDesc('id');

        return [$requete, $f];
    }

    /**
     * Entrees caisse de la periode : paiements des eleves, total par caissier
     * (frais de l'annee et dettes).
     *
     * @return array{nombre: int, montant: int, frais: int, dettes: int, par_caissier: list<array>}
     */
    private function caisse(TenantContext $tenant, array $f): array
    {
        $lignes = DB::table('reglement_lignes as rl')->join('reglements as g', 'g.id', '=', 'rl.reglement_id')
            ->leftJoin('users as u', 'u.id', '=', 'g.caissier_id')
            ->where('g.etablissement_id', $tenant->id())->where('g.annee_scolaire_id', $this->anneeId($tenant))
            ->when($f['du'], fn ($q, $d) => $q->whereDate('g.date_paiement', '>=', $d))
            ->when($f['au'], fn ($q, $d) => $q->whereDate('g.date_paiement', '<=', $d))
            ->groupBy('g.caissier_id', 'u.name', 'u.prenoms')
            ->selectRaw('g.caissier_id, u.name, u.prenoms, COUNT(DISTINCT g.id) AS nombre, SUM(rl.montant) AS montant,
                SUM(CASE WHEN rl.dette_id IS NULL THEN rl.montant ELSE 0 END) AS frais, SUM(CASE WHEN rl.dette_id IS NULL THEN 0 ELSE rl.montant END) AS dettes')
            ->get();

        return [
            'nombre' => (int) $lignes->sum('nombre'),
            'montant' => (int) $lignes->sum('montant'),
            'frais' => (int) $lignes->sum('frais'),
            'dettes' => (int) $lignes->sum('dettes'),
            'par_caissier' => $lignes->map(fn ($l) => [
                'id' => $l->caissier_id ? (int) $l->caissier_id : null,
                'nom' => trim(($l->name ?? '').' '.($l->prenoms ?? '')) ?: 'Caissier non renseigné',
                'nombre' => (int) $l->nombre,
                'frais' => (int) $l->frais,
                'dettes' => (int) $l->dettes,
                'montant' => (int) $l->montant,
            ])->sortByDesc('montant')->values()->all(),
        ];
    }

    /** Personnel habilite aux operations bancaires (droit depenses.retrait_banque). */
    private function habilites(): Collection
    {
        return DB::table('users as u')
            ->join('model_has_roles as mr', fn ($j) => $j->on('mr.model_id', '=', 'u.id')->where('mr.model_type', (new User)->getMorphClass()))
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->join('role_has_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('r.etablissement_id', app(TenantContext::class)->id())->where('r.is_active', true)
            ->where('p.name', 'depenses.retrait_banque')->whereNull('u.deleted_at')->where('u.statut', 'actif')
            ->distinct()->orderBy('u.name')->orderBy('u.prenoms')->get(['u.id', 'u.name', 'u.prenoms'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'nom' => trim($u->prenoms.' '.$u->name)]);
    }

    private function valider(Request $request, bool $modification = false): array
    {
        return $request->validate(array_merge($modification ? ['motif' => ['required', 'string', 'min:3', 'max:500']] : [], [
            'banque_id' => ['required', 'integer', Rule::exists('banques', 'id')->where('etablissement_id', app(TenantContext::class)->id())],
            'verse_par_id' => ['nullable', 'integer', Rule::in($this->habilites()->pluck('id')->all())],
            'date_entree' => ['required', 'date', 'before_or_equal:today'],
            'categorie' => ['required', Rule::in(array_keys(Entree::CATEGORIES))],
            'libelle' => ['required', 'string', 'max:300'],
            'montant' => ['required', 'integer', 'min:1'],
            'mode_paiement' => ['required', Rule::in(array_keys(Entree::MODES))],
            'provenance' => ['nullable', 'string', 'max:150'],
            'piece_comptable' => ['nullable', 'string', 'max:100'],
            'justificatif' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]), [
            'motif.required' => 'Le motif de la modification est obligatoire.',
            'motif.min' => 'Précisez le motif (3 caractères au moins).',
            'date_entree.before_or_equal' => 'La date ne peut pas être dans le futur.',
            'libelle.required' => 'Précisez l\'objet de l\'entrée.',
            'montant.min' => 'Le montant doit être positif.',
            'justificatif.mimes' => 'Le justificatif doit être une image ou un PDF.',
            'justificatif.max' => 'Le justificatif ne doit pas dépasser 5 Mo.',
            'banque_id.required' => 'Choisissez la banque.',
            'banque_id.exists' => 'Cette banque n\'existe pas.',
            'verse_par_id.in' => 'Cette personne n\'est pas habilitée aux opérations bancaires.',
        ]);
    }

    private function champs(array $data): array
    {
        return [
            'banque_id' => (int) $data['banque_id'],
            'verse_par_id' => ! empty($data['verse_par_id']) ? (int) $data['verse_par_id'] : null,
            'date_entree' => $data['date_entree'],
            'categorie' => $data['categorie'],
            'libelle' => trim($data['libelle']),
            'montant' => (int) $data['montant'],
            'mode_paiement' => $data['mode_paiement'],
            'provenance' => $data['provenance'] ?? null,
            'piece_comptable' => $data['piece_comptable'] ?? null,
        ];
    }

    private function enregistrerJustificatif(Request $request, Entree $entree): void
    {
        if ($request->hasFile('justificatif')) {
            $chemin = $request->file('justificatif')->store('entrees/'.$entree->etablissement_id, 'public');
            $entree->update(['piece_justificative_path' => $chemin]);
        }
    }

    /** "E2627-00001" : code de l'annee + ordre dans l'etablissement. */
    private function numero(int $anneeId): string
    {
        $libelle = AnneeScolaire::find($anneeId)?->libelle ?? '';
        $prefixe = 'E'.(preg_match('/^\d{2}(\d{2})-\d{2}(\d{2})$/', $libelle, $m) ? $m[1].$m[2] : $anneeId);
        $dernier = Entree::where('numero', 'like', $prefixe.'-%')->lockForUpdate()->max('numero');

        return $prefixe.'-'.str_pad((string) (($dernier ? (int) substr($dernier, strlen($prefixe) + 1) : 0) + 1), 5, '0', STR_PAD_LEFT);
    }

    /** Une entree ; sans "Voir les entrees", seulement les siennes. */
    private function trouver(Request $request, int $id): Entree
    {
        abort_unless($request->user()->can('entrees.gerer') || $request->user()->can('entrees.voir'), 403);
        $entree = Entree::findOrFail($id);
        abort_if(! $request->user()->can('entrees.voir') && $entree->saisi_par_id !== $request->user()->id, 403);

        return $entree;
    }

    private function instantane(Entree $e): array
    {
        return [
            'numero' => $e->numero,
            'date_entree' => $e->date_entree?->toDateString(),
            'categorie' => $e->categorie,
            'libelle' => $e->libelle,
            'montant' => (int) $e->montant,
            'banque_id' => $e->banque_id,
            'verse_par_id' => $e->verse_par_id,
            'mode_paiement' => $e->mode_paiement,
            'provenance' => $e->provenance,
            'piece_comptable' => $e->piece_comptable,
            'piece_justificative_path' => $e->piece_justificative_path,
        ];
    }

    private function historiser(Entree $e, string $action, string $motif, array $avant, ?array $apres, int $userId): void
    {
        DB::table('entrees_historique')->insert([
            'etablissement_id' => $e->etablissement_id,
            'entree_id' => $e->id,
            'numero' => $e->numero,
            'action' => $action,
            'motif' => mb_substr(trim($motif), 0, 500),
            'avant' => json_encode($avant, JSON_UNESCAPED_UNICODE),
            'apres' => $apres ? json_encode($apres, JSON_UNESCAPED_UNICODE) : null,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Lignes [libelle, nombre, montant] triees par montant decroissant. */
    private function bilan(Collection $entrees, \Closure $cle): array
    {
        return $entrees->groupBy($cle)->map(fn ($g, $libelle) => ['libelle' => (string) $libelle, 'nombre' => $g->count(), 'montant' => (int) $g->sum('montant')])
            ->sortByDesc('montant')->values()->all();
    }

    private function nomBanque(Banque $b): string
    {
        return trim(($b->sigle ? $b->sigle.' · ' : '').$b->nom);
    }

    private function presenter(Entree $e): array
    {
        return [
            'id' => $e->id,
            'numero' => $e->numero,
            'date' => $e->date_entree?->toDateString(),
            'categorie' => $e->categorie,
            'categorie_libelle' => Entree::CATEGORIES[$e->categorie] ?? $e->categorie,
            'libelle' => $e->libelle,
            'montant' => (int) $e->montant,
            'banque_id' => $e->banque_id,
            'banque' => $e->banque ? $this->nomBanque($e->banque) : null,
            'verse_par_id' => $e->verse_par_id,
            'verse_par' => $e->versePar ? trim($e->versePar->prenoms.' '.$e->versePar->name) : null,
            'mode' => $e->mode_paiement,
            'provenance' => $e->provenance,
            'piece_comptable' => $e->piece_comptable,
            'justificatif_url' => $e->piece_justificative_path ? Storage::disk('public')->url($e->piece_justificative_path) : null,
            'justificatif_pdf' => $e->piece_justificative_path ? str_ends_with(strtolower($e->piece_justificative_path), '.pdf') : false,
            'saisi_par_id' => $e->saisi_par_id,
            'saisi_par' => $e->saisiPar ? trim($e->saisiPar->name.' '.$e->saisiPar->prenoms) : null,
            'saisie_le' => $e->created_at?->toDateTimeString(),
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
