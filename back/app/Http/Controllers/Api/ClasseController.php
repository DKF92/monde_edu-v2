<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Classe;
use App\Models\Etablissement;
use App\Models\Inscription;
use App\Models\Niveau;
use App\Models\Personnel;
use App\Support\Document;
use App\Support\PorteePedagogique;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Classes de l'annee de travail (V1 new_classe / liste_classes /
 * liste_eleve_classe) :
 * - classes.voir  : liste des classes avec leurs effectifs, liste de classe ;
 * - classes.gerer : creer, modifier, supprimer une classe (sans eleve).
 * Un poste rattache a des niveaux (educateur) ne voit que ses niveaux.
 *
 * Libelle = niveau + nom court (V1 lib_niveau + lib_classe) : "6EME 1", "2NDE A".
 */
class ClasseController extends Controller
{
    public const LANGUES = InscriptionController::LANGUES;

    public function index(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request, 'voir');
        $annee = $this->annee($tenant);
        $autorises = $this->niveauxAutorises($request, $annee->id);

        $classes = $this->classes($annee->id, $autorises);
        $niveaux = Niveau::when($autorises !== null, fn (Builder $q) => $q->whereIn('id', $autorises))
            ->orderBy('ordre')->get(['id', 'code', 'libelle', 'cycle', 'ordre']);
        $numerotation = $this->numerotation($tenant);

        return response()->json([
            'annee' => $annee->libelle,
            'annee_cloturee' => (bool) $annee->is_cloturee,
            'data' => $classes,
            'totaux' => $this->totaux($classes),
            'niveaux' => $niveaux,
            'numerotation' => $numerotation,
            // Nom propose pour la prochaine classe de chaque niveau.
            'prochains' => $niveaux->mapWithKeys(fn (Niveau $n) => [$n->id => $this->suivants($annee->id, $n->id, 1, $numerotation)[0] ?? null]),
            'niveaux_restreints' => $autorises !== null,
            'personnels' => $this->personnels(),
            'langues' => collect(self::LANGUES)->merge(collect($classes)->pluck('langue_vivante_2')->filter())->unique()->values(),
        ]);
    }

    /** Liste des classes imprimable (PDF, Word, Excel). */
    public function document(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request, 'voir');
        $annee = $this->annee($tenant);
        // ids=1,2,3 : les classes affichees a l'ecran (filtres compris).
        $ids = $request->filled('ids') ? collect(explode(',', (string) $request->query('ids')))->map(fn ($v) => (int) $v)->all() : null;
        $classes = collect($this->classes($annee->id, $this->niveauxAutorises($request, $annee->id)))
            ->when($ids !== null, fn (Collection $c) => $c->whereIn('id', $ids))
            ->when($request->integer('niveau_id'), fn (Collection $c, $id) => $c->where('niveau.id', $id))
            ->when($request->filled('cycle'), fn (Collection $c) => $c->where('niveau.cycle', $request->query('cycle')))
            ->values();

        return Document::repondre('pdf.classes', Document::entete(Etablissement::findOrFail($tenant->id())) + [
            'annee' => $annee->libelle,
            'classes' => $classes,
            'totaux' => $this->totaux($classes->all()),
            'genere_le' => now(),
            'edite_par' => trim($request->user()->name.' '.$request->user()->prenoms),
        ], 'classes-'.$annee->libelle, $request->query('format'), 'landscape');
    }

    /** Liste de classe : la classe et ses eleves (V1 liste_eleve_classe). */
    public function show(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request, 'voir');
        $classe = $this->trouver($request, $tenant, $id);
        $annee = $this->annee($tenant);

        return response()->json([
            'annee' => $annee->libelle,
            'classe' => collect($this->classes($annee->id, null, $classe->id))->first(),
            'eleves' => $this->eleves($classe),
            // Autres classes pour passer d'une liste a l'autre (V1 : liste deroulante).
            'autres' => Classe::where('annee_scolaire_id', $annee->id)
                ->when($this->niveauxAutorises($request, $annee->id) !== null, fn (Builder $q) => $q->whereIn('niveau_id', $this->niveauxAutorises($request, $annee->id)))
                ->join('niveaux', 'niveaux.id', '=', 'classes.niveau_id')
                ->orderBy('niveaux.ordre')->orderBy('classes.libelle')
                ->get(['classes.id', 'classes.libelle']),
        ]);
    }

    /** Liste de classe imprimable : tous les eleves, ou affectes (affecte=1/0) et/ou d'un sexe (sexe=M/F). */
    public function documentEleves(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request, 'voir');
        $classe = $this->trouver($request, $tenant, $id);
        $annee = $this->annee($tenant);
        $eleves = collect($this->eleves($classe))
            ->when($request->filled('affecte'), fn (Collection $c) => $c->where('affecte', $request->boolean('affecte')))
            ->when(in_array($request->query('sexe'), ['M', 'F'], true), fn (Collection $c) => $c->where('sexe', $request->query('sexe')))
            ->when($request->filled('redoublant'), fn (Collection $c) => $c->where('redoublant', $request->boolean('redoublant')))
            ->values();
        // Ex : "Filles non affectées", "Élèves affectés", "Garçons", "Doublants".
        $sexe = $request->query('sexe');
        $affecte = $request->filled('affecte') ? ($request->boolean('affecte') ? 'affecté' : 'non affecté').($sexe === 'F' ? 'es' : 's') : null;
        $filtre = collect([$sexe === 'M' ? 'Garçons' : ($sexe === 'F' ? 'Filles' : ($affecte ? 'Élèves' : null)), $affecte])->filter()->implode(' ');
        if ($request->filled('redoublant')) {
            $filtre = trim($filtre.' '.($request->boolean('redoublant') ? ($filtre ? 'doublants' : 'Doublants') : ($filtre ? 'non doublants' : 'Non doublants')));
        }

        return Document::repondre('pdf.liste-classe', Document::entete(Etablissement::findOrFail($tenant->id())) + [
            'annee' => $annee->libelle,
            'listes' => [['classe' => collect($this->classes($annee->id, null, $classe->id))->first(), 'eleves' => $eleves, 'filtre' => $filtre ?: null]],
            'genere_le' => now(),
            'edite_par' => trim($request->user()->name.' '.$request->user()->prenoms),
        ], 'liste-'.str_replace(' ', '-', strtolower($classe->libelle)), $request->query('format'));
    }

    /**
     * Listes de plusieurs classes dans un seul document, une classe par page :
     * les classes affichees dans le tableau (ids=1,2,3) ou toutes celles du poste.
     */
    public function documentListes(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request, 'voir');
        $annee = $this->annee($tenant);
        $ids = collect(explode(',', (string) $request->query('ids')))->map(fn ($v) => (int) $v)->filter()->values();
        $classes = collect($this->classes($annee->id, $this->niveauxAutorises($request, $annee->id)))
            ->when($ids->isNotEmpty(), fn (Collection $c) => $c->whereIn('id', $ids->all()))
            ->values();
        abort_if($classes->isEmpty(), 422, 'Aucune classe à imprimer.');
        $modeles = Classe::whereIn('id', $classes->pluck('id'))->get()->keyBy('id');

        return Document::repondre('pdf.liste-classe', Document::entete(Etablissement::findOrFail($tenant->id())) + [
            'annee' => $annee->libelle,
            'listes' => $classes->map(fn (array $c) => ['classe' => $c, 'eleves' => $this->eleves($modeles[$c['id']]), 'filtre' => null])->all(),
            'genere_le' => now(),
            'edite_par' => trim($request->user()->name.' '.$request->user()->prenoms),
        ], 'listes-classes-'.$annee->libelle, $request->query('format'));
    }

    public function store(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request, 'gerer');
        $annee = $this->annee($tenant);
        abort_if($annee->is_cloturee, 422, 'L\'année '.$annee->libelle.' est clôturée.');

        $data = $this->valider($request, $annee->id);
        $classe = Classe::create($data + ['etablissement_id' => $tenant->id(), 'annee_scolaire_id' => $annee->id]);

        return response()->json(collect($this->classes($annee->id, null, $classe->id))->first(), 201);
    }

    /**
     * Creation de plusieurs classes d'un niveau, numerotees a la suite de la
     * plus grande existante (6EME 2 existe, 3 classes : 6EME 3, 6EME 4, 6EME 5).
     */
    public function storeLot(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request, 'gerer');
        $annee = $this->annee($tenant);
        abort_if($annee->is_cloturee, 422, 'L\'année '.$annee->libelle.' est clôturée.');

        $data = $request->validate([
            'niveau_id' => ['required', 'integer', Rule::exists('niveaux', 'id')],
            'nombre' => ['required', 'integer', 'min:1', 'max:20'],
        ], [
            'nombre.min' => 'Au moins 1 classe.',
            'nombre.max' => '20 classes au plus à la fois.',
        ]);
        $autorises = $this->niveauxAutorises($request, $annee->id);
        if ($autorises !== null && ! in_array((int) $data['niveau_id'], $autorises, true)) {
            throw ValidationException::withMessages(['niveau_id' => ['Ce niveau ne fait pas partie de vos niveaux.']]);
        }

        $niveau = Niveau::findOrFail($data['niveau_id']);
        $noms = $this->suivants($annee->id, $niveau->id, (int) $data['nombre'], $this->numerotation($tenant));
        if (count($noms) < $data['nombre']) {
            throw ValidationException::withMessages(['nombre' => ['Les lettres s\'arrêtent à Z : '.count($noms).' classe'.(count($noms) > 1 ? 's' : '').' au plus.']]);
        }

        $ids = DB::transaction(fn () => collect($noms)->map(fn ($nom) => Classe::create([
            'etablissement_id' => $tenant->id(),
            'annee_scolaire_id' => $annee->id,
            'niveau_id' => $niveau->id,
            'libelle' => mb_strtoupper($niveau->libelle.' '.$nom),
        ])->id)->all());

        return response()->json(collect($this->classes($annee->id, null))->whereIn('id', $ids)->values(), 201);
    }

    public function update(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request, 'gerer');
        $classe = $this->trouver($request, $tenant, $id);
        $annee = $this->annee($tenant);
        abort_if($annee->is_cloturee, 422, 'L\'année '.$annee->libelle.' est clôturée.');

        $data = $this->valider($request, $annee->id, $classe);
        if ((int) $data['niveau_id'] !== (int) $classe->niveau_id && $classe->inscriptions()->exists()) {
            throw ValidationException::withMessages(['niveau_id' => ['La classe a des élèves : son niveau ne peut plus changer.']]);
        }
        $classe->update($data);

        return response()->json(collect($this->classes($annee->id, null, $classe->id))->first());
    }

    /** Suppression d'une classe vide uniquement (V1 : "Supprimer classe"). */
    public function destroy(Request $request, TenantContext $tenant, int $id)
    {
        $this->autoriser($request, 'gerer');
        $classe = $this->trouver($request, $tenant, $id);

        $eleves = $classe->inscriptions()->count();
        abort_if($eleves > 0, 422, $classe->libelle.' compte '.$eleves.' élève'.($eleves > 1 ? 's' : '').' : retirez-les de la classe avant de la supprimer.');
        abort_if(DB::table('notes')->where('classe_id', $classe->id)->exists(), 422, 'Des notes sont enregistrées pour '.$classe->libelle.' : elle ne peut pas être supprimée.');

        $classe->delete();

        return response()->json(['message' => 'Classe '.$classe->libelle.' supprimée.']);
    }

    // ------------------------------------------------------------ Donnees

    /**
     * Classes de l'annee avec leurs effectifs (garcons, filles, affectes,
     * redoublants, etapes de paiement) et leur effectif maximum.
     */
    private function classes(int $anneeId, ?array $niveaux, ?int $classeId = null): array
    {
        $effectifs = Inscription::where('annee_scolaire_id', $anneeId)
            ->whereNotNull('classe_id')
            ->when($classeId, fn (Builder $q) => $q->where('classe_id', $classeId))
            ->join('eleves', 'eleves.id', '=', 'inscriptions.eleve_id')
            ->groupBy('inscriptions.classe_id')
            ->selectRaw("inscriptions.classe_id,
                COUNT(*) AS total,
                SUM(eleves.sexe = 'M') AS garcons,
                SUM(eleves.sexe = 'F') AS filles,
                SUM(inscriptions.affecte = 1) AS affectes,
                SUM(inscriptions.redoublant = 1) AS redoublants,
                SUM(inscriptions.statut = ".Inscription::CLASSE_CHOISIE.') AS attente_versement,
                SUM(inscriptions.statut = '.Inscription::PAIEMENT.') AS attente_solde,
                SUM(inscriptions.statut = '.Inscription::SOLDE.') AS soldes')
            ->get()->keyBy('classe_id');

        return Classe::where('classes.annee_scolaire_id', $anneeId)
            ->when($classeId, fn (Builder $q) => $q->whereKey($classeId))
            ->when($niveaux !== null, fn (Builder $q) => $q->whereIn('classes.niveau_id', $niveaux))
            ->with(['niveau:id,code,libelle,cycle,ordre', 'professeurPrincipal.user:id,name,prenoms', 'educateur.user:id,name,prenoms'])
            ->get()
            ->sortBy(fn (Classe $c) => [$c->niveau?->ordre ?? 99, $this->rang($c->libelle)])
            ->map(function (Classe $c) use ($effectifs) {
                $e = $effectifs->get($c->id);
                $total = (int) ($e->total ?? 0);
                $limite = $c->effectifLimite();

                return [
                    'id' => $c->id,
                    'libelle' => $c->libelle,
                    'nom' => $this->nomCourt($c),
                    'niveau' => $c->niveau?->only(['id', 'code', 'libelle', 'cycle', 'ordre']),
                    'salle' => $c->salle,
                    'capacite' => $c->capacite,
                    'limite' => $limite['limite'],
                    'source_limite' => $limite['source'],
                    'langue_vivante_2' => $c->langue_vivante_2,
                    'professeur_principal' => $this->personne($c->professeurPrincipal),
                    'educateur' => $this->personne($c->educateur),
                    'effectif' => $total,
                    'garcons' => (int) ($e->garcons ?? 0),
                    'filles' => (int) ($e->filles ?? 0),
                    'affectes' => (int) ($e->affectes ?? 0),
                    'non_affectes' => $total - (int) ($e->affectes ?? 0),
                    'redoublants' => (int) ($e->redoublants ?? 0),
                    'attente_versement' => (int) ($e->attente_versement ?? 0),
                    'attente_solde' => (int) ($e->attente_solde ?? 0),
                    'soldes' => (int) ($e->soldes ?? 0),
                    'complete' => $limite['limite'] !== null && $total >= $limite['limite'],
                ];
            })
            ->values()->all();
    }

    private function totaux(array $classes): array
    {
        $c = collect($classes);

        return [
            'classes' => $c->count(),
            'effectif' => (int) $c->sum('effectif'),
            'garcons' => (int) $c->sum('garcons'),
            'filles' => (int) $c->sum('filles'),
            'affectes' => (int) $c->sum('affectes'),
            'non_affectes' => (int) $c->sum('non_affectes'),
            'redoublants' => (int) $c->sum('redoublants'),
            'completes' => $c->where('complete', true)->count(),
        ];
    }

    /** Eleves de la classe, par ordre alphabetique. */
    private function eleves(Classe $classe): array
    {
        return $classe->inscriptions()
            ->join('eleves', 'eleves.id', '=', 'inscriptions.eleve_id')
            ->orderBy('eleves.nom')->orderBy('eleves.prenoms')->orderBy('eleves.matricule')
            ->get([
                'inscriptions.id', 'inscriptions.eleve_id', 'inscriptions.statut', 'inscriptions.affecte',
                'inscriptions.redoublant', 'inscriptions.langue_vivante_2', 'inscriptions.inscrit_en_ligne',
                'eleves.matricule', 'eleves.nom', 'eleves.prenoms', 'eleves.sexe', 'eleves.date_naissance',
                'eleves.lieu_naissance', 'eleves.telephone',
            ])
            ->map(fn ($i) => [
                'inscription_id' => $i->id,
                'eleve_id' => $i->eleve_id,
                'matricule' => $i->matricule,
                'nom' => $i->nom,
                'prenoms' => $i->prenoms,
                'sexe' => $i->sexe,
                'date_naissance' => $i->date_naissance ? substr((string) $i->date_naissance, 0, 10) : null,
                'lieu_naissance' => $i->lieu_naissance,
                'telephone' => $i->telephone,
                'affecte' => (bool) $i->affecte,
                'redoublant' => (bool) $i->redoublant,
                'langue_vivante_2' => $i->langue_vivante_2,
                'statut' => (int) $i->statut,
            ])
            ->values()->all();
    }

    private function personnels(): array
    {
        return Personnel::with('user:id,name,prenoms')->get()
            ->map(fn (Personnel $p) => $this->personne($p))
            ->filter()->sortBy('nom')->values()->all();
    }

    private function personne(?Personnel $p): ?array
    {
        return $p?->user ? ['id' => $p->id, 'nom' => trim($p->user->name.' '.$p->user->prenoms)] : null;
    }

    // ------------------------------------------------------------ Saisie

    private function valider(Request $request, int $anneeId, ?Classe $classe = null): array
    {
        $data = $request->validate([
            'niveau_id' => ['required', 'integer', Rule::exists('niveaux', 'id')],
            'nom' => ['required', 'string', 'max:5', 'regex:/^[A-Za-z0-9]+$/'],
            'salle' => ['nullable', 'string', 'max:30'],
            'capacite' => ['nullable', 'integer', 'min:1', 'max:500'],
            'langue_vivante_2' => ['nullable', 'string', 'max:30'],
            'professeur_principal_id' => ['nullable', 'integer', Rule::exists('personnels', 'id')->where('etablissement_id', app(TenantContext::class)->id())],
            'educateur_id' => ['nullable', 'integer', Rule::exists('personnels', 'id')->where('etablissement_id', app(TenantContext::class)->id())],
        ], [
            'nom.regex' => 'Lettres et chiffres uniquement (ex : 1, 2, A, B).',
            'nom.max' => '5 caractères maximum.',
        ]);

        $autorises = $this->niveauxAutorises($request, $anneeId);
        if ($autorises !== null && ! in_array((int) $data['niveau_id'], $autorises, true)) {
            throw ValidationException::withMessages(['niveau_id' => ['Ce niveau ne fait pas partie de vos niveaux.']]);
        }

        // Numerotation de l'etablissement (Parametres > Classes) ; une classe
        // existante garde son nom meme si la numerotation a change depuis.
        $nom = mb_strtoupper($data['nom']);
        $inchange = $classe && $nom === mb_strtoupper($this->nomCourt($classe));
        $chiffres = $this->numerotation(app(TenantContext::class)) === 'chiffres';
        if (! $inchange && ! preg_match($chiffres ? '/^[1-9]\d{0,2}$/' : '/^[A-Z]$/', $nom)) {
            throw ValidationException::withMessages(['nom' => [$chiffres
                ? 'Numérotation en chiffres : un numéro de 1 à 999 (Paramètres > Classes).'
                : 'Numérotation en lettres : une lettre de A à Z (Paramètres > Classes).']]);
        }

        $niveau = Niveau::findOrFail($data['niveau_id']);
        $libelle = mb_strtoupper($niveau->libelle.' '.$nom);
        $existe = Classe::where('annee_scolaire_id', $anneeId)->where('niveau_id', $niveau->id)->where('libelle', $libelle)
            ->when($classe, fn (Builder $q) => $q->whereKeyNot($classe->id))
            ->exists();
        if ($existe) {
            throw ValidationException::withMessages(['nom' => ['La classe '.$libelle.' existe déjà cette année.']]);
        }

        return [
            'niveau_id' => $niveau->id,
            'libelle' => $libelle,
            'salle' => trim((string) ($data['salle'] ?? '')) ?: null,
            'capacite' => $data['capacite'] ?? null,
            'langue_vivante_2' => trim((string) ($data['langue_vivante_2'] ?? '')) ?: null,
            'professeur_principal_id' => $data['professeur_principal_id'] ?? null,
            'educateur_id' => $data['educateur_id'] ?? null,
        ];
    }

    // ------------------------------------------------------------ Outils

    private function autoriser(Request $request, string $action): void
    {
        $user = $request->user();
        abort_unless($action === 'voir' ? ($user->can('classes.voir') || $user->can('classes.gerer')) : $user->can('classes.gerer'), 403);
    }

    private function annee(TenantContext $tenant): AnneeScolaire
    {
        $annee = AnneeScolaire::find($tenant->anneeScolaireId() ?? AnneeScolaire::where('is_active', true)->value('id'));
        abort_unless($annee, 422, 'Aucune année scolaire active.');

        return $annee;
    }

    private function trouver(Request $request, TenantContext $tenant, int $id): Classe
    {
        $annee = $this->annee($tenant);
        $classe = Classe::where('annee_scolaire_id', $annee->id)->findOrFail($id);
        $autorises = $this->niveauxAutorises($request, $annee->id);
        abort_if($autorises !== null && ! in_array((int) $classe->niveau_id, $autorises, true), 403);

        return $classe;
    }

    /** Niveaux d'un poste rattache a des niveaux (educateur) : null = tous, [] = aucun. */
    private function niveauxAutorises(Request $request, int $anneeId): ?array
    {
        return PorteePedagogique::niveaux($request, $anneeId);
    }

    /** Numerotation des classes : chiffres (V1) ou lettres. */
    private function numerotation(TenantContext $tenant): string
    {
        return Etablissement::find($tenant->id())?->parametre('numerotation_classes') === 'lettres' ? 'lettres' : 'chiffres';
    }

    /**
     * Noms des $nombre prochaines classes du niveau, a la suite du plus grand
     * numero (ou de la plus grande lettre) existant ; moins de noms si les
     * lettres depassent Z.
     *
     * @return list<string>
     */
    private function suivants(int $anneeId, int $niveauId, int $nombre, string $numerotation): array
    {
        $noms = Classe::where('annee_scolaire_id', $anneeId)->where('niveau_id', $niveauId)->with('niveau:id,libelle')->get()
            ->map(fn (Classe $c) => mb_strtoupper($this->nomCourt($c)));

        if ($numerotation === 'chiffres') {
            $depart = (int) $noms->filter(fn ($n) => ctype_digit($n))->map(fn ($n) => (int) $n)->max();

            return array_map(fn ($i) => (string) ($depart + $i), range(1, $nombre));
        }

        $lettres = $noms->filter(fn ($n) => preg_match('/^[A-Z]$/', $n));
        $depart = $lettres->isEmpty() ? ord('A') - 1 : ord($lettres->max());

        return collect(range(1, $nombre))->map(fn ($i) => $depart + $i)->filter(fn ($o) => $o <= ord('Z'))->map(fn ($o) => chr($o))->values()->all();
    }

    /** Nom court de la classe : le libelle sans le niveau ("6EME A" -> "A"). */
    private function nomCourt(Classe $c): string
    {
        $prefixe = mb_strtoupper(($c->niveau?->libelle ?? '').' ');

        return str_starts_with(mb_strtoupper($c->libelle), $prefixe) ? mb_substr($c->libelle, mb_strlen($prefixe)) : $c->libelle;
    }

    /** Tri naturel des classes d'un niveau : 6EME 2 avant 6EME 10. */
    private function rang(string $libelle): string
    {
        return preg_replace_callback('/\d+/', fn ($m) => str_pad($m[0], 4, '0', STR_PAD_LEFT), $libelle);
    }
}
