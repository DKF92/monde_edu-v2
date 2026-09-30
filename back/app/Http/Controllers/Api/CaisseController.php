<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Dette;
use App\Models\Etablissement;
use App\Models\FraisEleve;
use App\Models\Inscription;
use App\Models\Reglement;
use App\Models\ReglementLigne;
use App\Services\CaisseService;
use App\Support\BilanCaisse;
use App\Support\Document;
use App\Support\FicheEleve;
use App\Support\PorteeFinances;
use App\Support\RecuPaiement;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Caisse (droit reglements.encaisser) et journal des paiements (droit
 * reglements.voir). Regles d'encaissement : voir CaisseService.
 */
class CaisseController extends Controller
{
    public const MODES = ['especes', 'mobile_money', 'cheque', 'virement'];

    public function __construct(private readonly CaisseService $caisse) {}

    // ------------------------------------------------------------ Encaisser

    /** Eleves inscrits cette annee (nom, prenoms, matricule) a encaisser. */
    public function rechercher(Request $request, TenantContext $tenant)
    {
        abort_unless($request->user()->can('reglements.encaisser'), 403);

        $texte = $request->string('q')->trim()->value();
        if (mb_strlen($texte) < 2) {
            return response()->json(['data' => []]);
        }

        $inscriptions = Inscription::where('inscriptions.annee_scolaire_id', $this->anneeId($tenant))
            ->join('eleves', 'eleves.id', '=', 'inscriptions.eleve_id')
            ->where(fn (Builder $q) => $q->where('eleves.matricule', 'like', "{$texte}%")
                ->orWhere('eleves.nom', 'like', "%{$texte}%")
                ->orWhere('eleves.prenoms', 'like', "%{$texte}%")
                ->orWhereRaw("CONCAT(eleves.nom, ' ', eleves.prenoms) LIKE ?", ["%{$texte}%"]))
            ->orderBy('eleves.nom')->orderBy('eleves.prenoms')
            ->select('inscriptions.*')
            ->with(['eleve', 'classe:id,libelle', 'niveau:id,libelle'])
            ->limit(20)
            ->get();

        $dettes = Dette::whereIn('eleve_id', $inscriptions->pluck('eleve_id'))->where('is_annulee', false)
            ->get()->groupBy('eleve_id')
            ->map(fn ($d) => (int) $d->sum(fn (Dette $x) => max(0, $x->resteAPayer())));

        return response()->json(['data' => $inscriptions->map(fn (Inscription $i) => [
            'id' => $i->id,
            'eleve' => [
                'id' => $i->eleve->id,
                'matricule' => $i->eleve->matricule,
                'nom' => $i->eleve->nom,
                'prenoms' => $i->eleve->prenoms,
                'sexe' => $i->eleve->sexe,
                'photo_url' => FicheEleve::photoUrl($i->eleve),
            ],
            'classe' => $i->classe?->libelle,
            'niveau' => $i->niveau?->libelle,
            'statut' => $i->statut,
            'reste' => (int) max(0, $i->resteAPayer()),
            'dettes' => $dettes[$i->eleve_id] ?? 0,
        ])->values()]);
    }

    /** Situation d'une inscription au guichet : frais, dettes, versements, bornes. */
    public function situation(Request $request, TenantContext $tenant, int $id)
    {
        abort_unless($request->user()->can('reglements.encaisser'), 403);

        return response()->json(['data' => $this->presenterSituation(Inscription::with('eleve.tuteurs')->findOrFail($id), $tenant)]);
    }

    public function encaisser(Request $request, TenantContext $tenant, int $id)
    {
        abort_unless($request->user()->can('reglements.encaisser'), 403);

        $inscription = Inscription::with('eleve.tuteurs')->findOrFail($id);
        $data = $request->validate([
            'montant' => ['required', 'integer', 'min:0'],
            'montant_dette' => ['nullable', 'integer', 'min:0'],
            'mode_paiement' => ['required', Rule::in(self::MODES)],
            'date_paiement' => ['nullable', 'date', 'before_or_equal:now'],
            'date_expiration' => ['nullable', 'date'],
            'fournitures' => ['array'],
            'fournitures.*.id' => ['required', 'integer'],
            'fournitures.*.quantite_remise' => ['required', 'integer', 'min:0'],
        ], [
            'montant.required' => 'Saisissez le montant versé.',
            'date_paiement.before_or_equal' => 'La date du paiement ne peut pas être dans le futur.',
        ]);
        // Date seule (V1 "date reglement") : on garde l'heure de l'encaissement.
        if (! empty($data['date_paiement']) && strlen($data['date_paiement']) <= 10) {
            $data['date_paiement'] = $data['date_paiement'].' '.now()->format('H:i:s');
        }

        $etablissement = Etablissement::findOrFail($tenant->id());
        $reglement = $this->caisse->encaisser($inscription, $etablissement, $data, $request->user()->id);
        $sms = $this->caisse->envoyerSms($reglement, $etablissement, $request->user()->id);

        return response()->json([
            'reglement' => $this->presenterReglement($reglement->fresh()),
            'sms' => $sms,
            'situation' => $this->presenterSituation($inscription->fresh('eleve.tuteurs'), $tenant),
        ], 201);
    }

    // ------------------------------------------------------------ Journal

    /**
     * Journal des paiements : periode, mode, caissier, recherche ; totaux.
     * Qui voit quoi :
     * - droit "Encaisser" : seulement ses propres encaissements, sauf recherche
     *   par matricule (tous les paiements de l'eleve, quel que soit l'encaisseur) ;
     * - droit "Voir les paiements" sans "Encaisser" : tous les paiements.
     * La recherche par matricule (exact) couvre toute l'annee scolaire.
     */
    public function journal(Request $request, TenantContext $tenant)
    {
        $encaisse = $request->user()->can('reglements.encaisser');
        abort_unless($encaisse || $request->user()->can('reglements.voir'), 403);

        $data = $request->validate([
            'periode' => ['nullable', Rule::in(['jour', 'semaine', 'mois', 'annee', 'dates'])],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'mode' => ['nullable', Rule::in(self::MODES)],
            'caissier_id' => ['nullable', 'integer'],
            'moi' => ['nullable', 'boolean'],
            'recherche' => ['nullable', 'string', 'max:100'],
            // Cellule du bilan : type de frais / dettes, statut de l'eleve.
            'type_frais_id' => ['nullable', 'integer'],
            'dette' => ['nullable', 'boolean'],
            'affecte' => ['nullable', 'boolean'],
            // Criteres eleve du bilan (inscription reglee).
            'sexe' => ['nullable', Rule::in(['M', 'F'])],
            'redoublant' => ['nullable', 'boolean'],
            'cycle' => ['nullable', Rule::in(array_keys(BilanCaisse::CYCLES))],
            'niveau_id' => ['nullable', 'integer'],
            'classe_id' => ['nullable', 'integer'],
            'matricule' => ['nullable', 'string', 'max:20'],
        ]);
        $matricule = strtoupper(trim($data['matricule'] ?? ''));
        // Parent : seulement les paiements de ses enfants.
        $enfants = PorteeFinances::elevesDuParent($request);
        if ($enfants !== null) {
            $encaisse = false;
        }
        if ($matricule !== '') {
            // Paiements d'un eleve : toute l'annee, tous encaisseurs, sans autre filtre que le mode.
            $data = ['periode' => 'annee', 'mode' => $data['mode'] ?? null];
            $criteres = BilanCaisse::criteres([]);
        } else {
            $criteres = BilanCaisse::criteres($request->only(['sexe', 'redoublant', 'cycle', 'niveau_id', 'classe_id']));
            if ($encaisse) {
                $data['caissier_id'] = $request->user()->id;
            }
        }
        $type = $matricule === '' ? ($data['type_frais_id'] ?? null) : null;
        $dette = $matricule === '' && $request->boolean('dette');
        $affecte = $matricule === '' && $request->filled('affecte') ? $request->boolean('affecte') : null;
        $filtreLignes = $type || $dette || $affecte !== null;
        $statut = fn (Builder $i) => $i->where('affecte', $affecte);
        $ligneRetenue = function (Builder $l) use ($type, $dette, $affecte, $statut) {
            if ($dette) {
                $l->whereNotNull('dette_id')->when($affecte !== null, fn ($q) => $q->whereHas('reglement.inscription', $statut));
            } elseif ($type) {
                $l->whereHas('fraisEleve', fn (Builder $f) => $f->where('type_frais_id', $type)
                    ->when($affecte !== null, fn ($q) => $q->whereHas('inscription', $statut)));
            } else {
                $l->where(fn (Builder $w) => $w->whereHas('fraisEleve.inscription', $statut)
                    ->orWhere(fn (Builder $d) => $d->whereNotNull('dette_id')->whereHas('reglement.inscription', $statut)));
            }
        };

        // Periodes calculees avec la date du serveur (le poste peut etre sur un autre fuseau).
        [$data['du'], $data['au']] = match ($data['periode'] ?? 'dates') {
            'jour' => [today()->toDateString(), today()->toDateString()],
            'semaine' => [today()->subDays(6)->toDateString(), today()->toDateString()],
            'mois' => [today()->startOfMonth()->toDateString(), today()->toDateString()],
            'annee' => [null, null],
            default => [$data['du'] ?? null, $data['au'] ?? null],
        };

        $base = Reglement::where('reglements.annee_scolaire_id', $this->anneeId($tenant))
            ->when($data['du'] ?? null, fn (Builder $q, $d) => $q->whereDate('reglements.date_paiement', '>=', $d))
            ->when($data['au'] ?? null, fn (Builder $q, $d) => $q->whereDate('reglements.date_paiement', '<=', $d))
            ->when($data['mode'] ?? null, fn (Builder $q, $m) => $q->where('reglements.mode_paiement', $m))
            ->when($data['caissier_id'] ?? null, fn (Builder $q, $c) => $q->where('reglements.caissier_id', $c))
            ->when($matricule !== '', fn (Builder $q) => $q->whereHas('eleve', fn (Builder $e) => $e->where('matricule', $matricule)))
            ->when($enfants !== null, fn (Builder $q) => $q->whereIn('reglements.eleve_id', $enfants ?: [0]))
            ->when(trim($data['recherche'] ?? '') !== '', function (Builder $q) use ($data) {
                $texte = trim($data['recherche']);
                $q->where(fn (Builder $w) => $w->where('reglements.numero_recu', 'like', "%{$texte}%")
                    ->orWhereHas('eleve', fn (Builder $e) => $e->where('matricule', 'like', "{$texte}%")
                        ->orWhere('nom', 'like', "%{$texte}%")->orWhere('prenoms', 'like', "%{$texte}%")
                        ->orWhereRaw("CONCAT(nom, ' ', prenoms) LIKE ?", ["%{$texte}%"])));
            })
            ->when($filtreLignes, fn (Builder $q) => $q->whereHas('lignes', $ligneRetenue))
            ->when($criteres['sexe'], fn (Builder $q, $s) => $q->whereHas('eleve', fn (Builder $e) => $e->where('sexe', $s)))
            ->when(array_filter([$criteres['redoublant'] !== null, $criteres['cycle'], $criteres['niveau_id'], $criteres['classe_id']]), fn (Builder $q) => $q->whereHas('inscription', function (Builder $i) use ($criteres) {
                $i->when($criteres['redoublant'] !== null, fn ($x) => $x->where('redoublant', $criteres['redoublant']))
                    ->when($criteres['classe_id'], fn ($x, $id) => $x->where('classe_id', $id))
                    ->when(! $criteres['classe_id'] && $criteres['niveau_id'], fn ($x) => $x->where('niveau_id', $criteres['niveau_id']))
                    ->when(! $criteres['classe_id'] && ! $criteres['niveau_id'] && $criteres['cycle'], fn ($x) => $x->whereHas('niveau', fn ($n) => $n->where('cycle', $criteres['cycle'])));
            }));

        $parMode = (clone $base)->selectRaw('mode_paiement, COUNT(*) AS nombre, SUM(montant_total) AS total')
            ->groupBy('mode_paiement')->get()->keyBy('mode_paiement');

        $page = (clone $base)->with(['eleve', 'inscription.classe:id,libelle', 'caissier:id,name,prenoms'])
            ->orderByDesc('reglements.date_paiement')->orderByDesc('reglements.id')
            ->paginate(min(max($request->integer('par_page', 50), 1), 200));

        // Cellule du bilan : part de chaque paiement qui a regle ces frais.
        $parts = $filtreLignes
            ? ReglementLigne::whereIn('reglement_id', collect($page->items())->pluck('id'))->where($ligneRetenue)
                ->groupBy('reglement_id')->selectRaw('reglement_id, SUM(montant) AS part')->pluck('part', 'reglement_id')
            : collect();
        $totalParts = $filtreLignes
            ? (int) ReglementLigne::whereIn('reglement_id', (clone $base)->select('reglements.id'))->where($ligneRetenue)->sum('montant')
            : null;

        return response()->json([
            'data' => collect($page->items())->map(fn (Reglement $r) => $this->presenterReglement($r, false)
                + ['part' => $filtreLignes ? (int) ($parts[$r->id] ?? 0) : null])->values(),
            'total_parts' => $totalParts,
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'par_page' => $page->perPage(),
            'du' => $data['du'],
            'au' => $data['au'],
            'totaux' => [
                'nombre' => (int) $parMode->sum('nombre'),
                'montant' => (int) $parMode->sum('total'),
                'par_mode' => collect(self::MODES)->mapWithKeys(fn ($m) => [$m => (int) ($parMode[$m]->total ?? 0)]),
            ],
            // Portee appliquee : ses encaissements (droit Encaisser) ou tout.
            'mes_encaissements' => $encaisse && $matricule === '',
            'matricule' => $matricule !== '' ? $matricule : null,
            'caissiers' => ($encaisse || $enfants !== null) ? [] : Reglement::where('annee_scolaire_id', $this->anneeId($tenant))
                ->with('caissier:id,name,prenoms')->get()->pluck('caissier')->filter()->unique('id')
                ->map(fn ($u) => ['id' => $u->id, 'nom' => trim($u->name.' '.$u->prenoms)])->values(),
        ]);
    }

    // ------------------------------------------------------------ Bilan

    /** Point des paiements encaisses par l'utilisateur connecte (types de frais x statut). */
    public function bilan(Request $request, TenantContext $tenant)
    {
        return response()->json(['data' => $this->donneesBilan($request, $tenant)]);
    }

    /** Bilan imprimable (PDF genere a la demande). */
    public function bilanPdf(Request $request, TenantContext $tenant)
    {
        $bilan = $this->donneesBilan($request, $tenant);

        return Document::repondre('pdf.bilan-caisse', ['b' => $bilan] + Document::entete(Etablissement::findOrFail($tenant->id())),
            'bilan-caisse', $request->query('format'));
    }

    /**
     * Bilan general PDF : general, par genre, redoublement, cycle, niveau et
     * classe (chaque sous-bilan ajoute son critere a ceux choisis).
     */
    public function bilanGeneralPdf(Request $request, TenantContext $tenant)
    {
        $bilan = $this->donneesBilan($request, $tenant, false);
        $bilan['sections'] = BilanCaisse::general($bilan['annee_id'], $bilan['caissier']['id'], $bilan['periode'], $bilan['criteres']);

        return Document::repondre('pdf.bilan-caisse-general', ['b' => $bilan] + Document::entete(Etablissement::findOrFail($tenant->id())),
            'bilan-general', $request->query('format'));
    }

    private function donneesBilan(Request $request, TenantContext $tenant, bool $calculer = true): array
    {
        abort_unless($request->user()->can('reglements.encaisser'), 403);

        $filtres = $request->validate([
            'periode' => ['nullable', Rule::in(['jour', 'dates', 'mois', 'annee'])],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'mois' => ['nullable', 'string'],
            'sexe' => ['nullable', Rule::in(['M', 'F'])],
            'redoublant' => ['nullable', 'boolean'],
            'cycle' => ['nullable', Rule::in(array_keys(BilanCaisse::CYCLES))],
            'niveau_id' => ['nullable', 'integer'],
            'classe_id' => ['nullable', 'integer'],
        ]);
        $annee = AnneeScolaire::find($this->anneeId($tenant));
        abort_unless($annee, 422, 'Aucune année scolaire active.');
        $periode = BilanCaisse::periode($filtres, $annee);
        $criteres = BilanCaisse::criteres($filtres);
        $libelleCriteres = BilanCaisse::libelleCriteres($criteres);
        $user = $request->user();

        $donnees = [
            'periode' => $periode,
            'titre' => 'Bilan de '.$periode['libelle'].($libelleCriteres ? ' · '.$libelleCriteres : ''),
            'annee' => $annee->libelle,
            'annee_id' => $annee->id,
            'criteres' => $criteres,
            'criteres_libelle' => $libelleCriteres,
            'mois_disponibles' => BilanCaisse::moisDeLAnnee($annee),
            'criteres_disponibles' => BilanCaisse::criteresDisponibles($annee->id),
            'caissier' => ['id' => $user->id, 'nom' => trim($user->name.' '.$user->prenoms)],
            'genere_le' => now()->toDateTimeString(),
        ];

        return $calculer ? $donnees + BilanCaisse::calculer($annee->id, $user->id, $periode, $criteres) : $donnees;
    }

    /**
     * Detail d'un paiement : le paiement (repartition), la situation de
     * l'inscription, l'historique des corrections et, pour la modification,
     * les bornes du montant sans ce paiement.
     */
    public function show(Request $request, TenantContext $tenant, int $id)
    {
        abort_unless($request->user()->can('reglements.voir') || $request->user()->can('reglements.encaisser'), 403);

        $reglement = $this->reglementVisible($request, $id);
        $inscription = $reglement->inscription_id ? Inscription::with('eleve.tuteurs')->find($reglement->inscription_id) : null;
        $lignes = $reglement->lignes()->get();
        $partFrais = (int) $lignes->whereNotNull('frais_eleve_id')->sum('montant');
        $partDette = (int) $lignes->whereNotNull('dette_id')->sum('montant');

        $modification = null;
        if ($inscription) {
            // Bornes comme si ce paiement n'existait pas (ses lignes sont rendues).
            $etablissement = Etablissement::findOrFail($tenant->id());
            $bornes = $this->caisse->bornes($inscription, $etablissement, $reglement->numero_versement ?? 1);
            $maximum = (int) max(0, $inscription->resteAPayer()) + $partFrais;
            $modification = [
                'montant' => $partFrais,
                'montant_dette' => $partDette,
                'maximum' => $maximum,
                'minimum' => $bornes['doit_solder'] ? $maximum : min($bornes['minimum_regle'], $maximum),
                'doit_solder' => $bornes['doit_solder'],
                'dettes_maximum' => (int) $this->caisse->dettesOuvertes($inscription->eleve)->sum(fn (Dette $d) => $d->resteAPayer()) + $partDette,
            ];
        }

        return response()->json(['data' => [
            'reglement' => $this->presenterReglement($reglement),
            'situation' => $inscription ? $this->presenterSituation($inscription, $tenant) : null,
            'modification' => $modification,
            'historique' => DB::table('reglements_historique as h')->leftJoin('users as u', 'u.id', '=', 'h.user_id')
                ->where('h.reglement_id', $reglement->id)->orderByDesc('h.id')
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

    /** Corrige un paiement (droit reglements.modifier, motif obligatoire). */
    public function modifier(Request $request, TenantContext $tenant, int $id)
    {
        abort_unless($request->user()->can('reglements.modifier'), 403);

        $reglement = $this->reglementVisible($request, $id);
        abort_unless($reglement->inscription_id, 422, 'Ce paiement n\'est rattaché à aucune inscription.');
        $data = $request->validate([
            'motif' => ['required', 'string', 'min:3', 'max:500'],
            'montant' => ['required', 'integer', 'min:0'],
            'montant_dette' => ['nullable', 'integer', 'min:0'],
            'mode_paiement' => ['required', Rule::in(self::MODES)],
            'date_paiement' => ['nullable', 'date', 'before_or_equal:now'],
            'date_expiration' => ['nullable', 'date'],
        ], [
            'motif.required' => 'Le motif de la modification est obligatoire.',
            'motif.min' => 'Précisez le motif (3 caractères au moins).',
            'date_paiement.before_or_equal' => 'La date du paiement ne peut pas être dans le futur.',
        ]);
        if (! empty($data['date_paiement']) && strlen($data['date_paiement']) <= 10) {
            // Date seule : on garde l'heure d'origine du paiement.
            $data['date_paiement'] .= ' '.$reglement->date_paiement->format('H:i:s');
        }

        $reglement = $this->caisse->modifier($reglement, Etablissement::findOrFail($tenant->id()), $data, $data['motif'], $request->user()->id);

        return response()->json(['data' => $this->presenterReglement($reglement)]);
    }

    /** Supprime un paiement (droit reglements.modifier, motif obligatoire, historise). */
    public function supprimer(Request $request, int $id)
    {
        abort_unless($request->user()->can('reglements.modifier'), 403);

        $reglement = $this->reglementVisible($request, $id);
        $data = $request->validate(['motif' => ['required', 'string', 'min:3', 'max:500']], [
            'motif.required' => 'Le motif de la suppression est obligatoire.',
            'motif.min' => 'Précisez le motif (3 caractères au moins).',
        ]);
        $this->caisse->supprimer($reglement, $data['motif'], $request->user()->id);

        return response()->noContent();
    }

    /** Recu (PDF, Word ou Excel), genere a chaque demande a partir de la base (rien n'est stocke). */
    public function recu(Request $request, TenantContext $tenant, int $id)
    {
        abort_unless($request->user()->can('reglements.voir') || $request->user()->can('reglements.encaisser'), 403);

        $reglement = $this->reglementVisible($request, $id);

        return Document::repondre('pdf.recu-paiement', RecuPaiement::donnees($reglement, Etablissement::findOrFail($tenant->id())),
            'recu-'.$reglement->numero_recu, $request->query('format'));
    }

    /** Un parent n'ouvre que les recus de ses enfants. */
    private function reglementVisible(Request $request, int $id): Reglement
    {
        $reglement = Reglement::findOrFail($id);
        $enfants = PorteeFinances::elevesDuParent($request);
        abort_if($enfants !== null && ! in_array($reglement->eleve_id, $enfants, true), 403);

        return $reglement;
    }

    // ------------------------------------------------------------ Presentation

    private function presenterSituation(Inscription $inscription, TenantContext $tenant): array
    {
        $inscription->load(['classe:id,libelle', 'niveau:id,libelle', 'anneeScolaire:id,libelle', 'fraisEleves.typeFrais']);
        $eleve = $inscription->eleve;
        $etablissement = Etablissement::findOrFail($tenant->id());
        $frais = $inscription->fraisEleves->sortBy(fn (FraisEleve $f) => [$f->typeFrais->ordre, $f->id]);

        return [
            'id' => $inscription->id,
            'annee' => $inscription->anneeScolaire?->libelle,
            'eleve' => FicheEleve::presenter($eleve),
            'classe' => $inscription->classe?->libelle,
            'niveau' => $inscription->niveau?->libelle,
            'affecte' => (bool) $inscription->affecte,
            'statut' => $inscription->statut,
            'total_du' => (int) $inscription->montant_total_du,
            'total_reduit' => (int) $inscription->montant_total_reduit,
            'total_paye' => (int) $inscription->montant_total_paye,
            'reste' => (int) max(0, $inscription->resteAPayer()),
            'frais' => $frais->whereNull('quantite_due')->map(fn (FraisEleve $f) => [
                'id' => $f->id,
                'libelle' => $f->typeFrais->libelle,
                'montant_du' => (int) $f->montant_du,
                'montant_reduit' => (int) $f->montant_reduit,
                'montant_paye' => (int) $f->montant_paye,
                'reste' => (int) max(0, $f->resteAPayer()),
            ])->values(),
            'fournitures' => $frais->whereNotNull('quantite_due')->map(fn (FraisEleve $f) => [
                'id' => $f->id,
                'libelle' => $f->typeFrais->libelle,
                'quantite_due' => (int) $f->quantite_due,
                'quantite_remise' => (int) $f->quantite_remise,
            ])->values(),
            'dettes' => $this->caisse->dettesOuvertes($eleve)->map(fn (Dette $d) => [
                'id' => $d->id,
                'annee' => $d->anneeScolaire?->libelle,
                'reste' => (int) $d->resteAPayer(),
            ])->values(),
            'versements' => Reglement::where('inscription_id', $inscription->id)->orderBy('date_paiement')->orderBy('id')->get()
                ->map(fn (Reglement $r) => $this->presenterReglement($r, false))->values(),
            'bornes' => $inscription->classe_id ? $this->caisse->bornes($inscription, $etablissement) : null,
            'sms' => [
                'simulation' => (bool) config('services.sms.simulation'),
                'actif' => (bool) $etablissement->sms_actif,
                'credit' => (int) $etablissement->sms_credit,
            ],
        ];
    }

    private function presenterReglement(Reglement $r, bool $lignes = true): array
    {
        $r->loadMissing(['eleve', 'inscription.classe:id,libelle', 'caissier:id,name,prenoms']);

        return [
            'id' => $r->id,
            'numero_recu' => $r->numero_recu,
            'numero_versement' => $r->numero_versement,
            'date' => $r->date_paiement?->toDateTimeString(),
            'date_expiration' => $r->date_expiration?->toDateString(),
            'montant' => (int) $r->montant_total,
            'mode' => $r->mode_paiement,
            'inscription_id' => $r->inscription_id,
            'eleve' => $r->eleve ? [
                'id' => $r->eleve->id,
                'matricule' => $r->eleve->matricule,
                'nom' => $r->eleve->nom,
                'prenoms' => $r->eleve->prenoms,
            ] : null,
            'classe' => $r->inscription?->classe?->libelle,
            'caissier' => $r->caissier ? trim($r->caissier->name.' '.$r->caissier->prenoms) : null,
            'lignes' => $lignes ? $r->lignes()->with(['fraisEleve.typeFrais', 'dette.anneeScolaire'])->get()
                ->map(fn (ReglementLigne $l) => [
                    'libelle' => $l->fraisEleve?->typeFrais?->libelle ?? trim('Dette '.($l->dette?->anneeScolaire?->libelle ?? 'année précédente')),
                    'dette' => (bool) $l->dette_id,
                    'montant' => (int) $l->montant,
                ])->values() : null,
        ];
    }

    private function anneeId(TenantContext $tenant): ?int
    {
        return $tenant->anneeScolaireId() ?? AnneeScolaire::where('is_active', true)->value('id');
    }
}
