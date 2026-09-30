<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Classe;
use App\Models\GrilleTarifaire;
use App\Models\Periode;
use App\Support\ArretNotes;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Parametres > Annees scolaires (droit "annees_scolaires.gerer").
 * V1 : tables "annee" et "trimestre" (type_decoupage, actif, trim_termine).
 *
 * - une seule annee active (celle proposee par defaut a la connexion) ;
 * - une seule periode active par annee ;
 * - cloturer = plus de saisie sur l'annee / la periode (reouvrable) ; une
 *   periode ne se cloture que par le directeur (notes.arreter, ArretNotes) ;
 * - pas de suppression : une annee porte des inscriptions et des paiements.
 */
class AnneeScolaireGestionController extends Controller
{
    public function index(Request $request)
    {
        $this->autoriser($request);

        $annees = AnneeScolaire::with(['periodes' => fn ($q) => $q->orderBy('numero')])
            ->withCount(['classes', 'inscriptions'])
            ->orderByDesc('libelle')
            ->get();

        return response()->json(['data' => $annees->map(fn (AnneeScolaire $a) => $this->presenter($a))->values()]);
    }

    public function store(Request $request, TenantContext $tenant)
    {
        $this->autoriser($request);

        $data = $request->validate([
            'libelle' => [
                'required', 'regex:/^\d{4}-\d{4}$/',
                Rule::unique('annees_scolaires', 'libelle')->where('etablissement_id', $tenant->id()),
            ],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after:date_debut'],
            'type_decoupage' => ['required', Rule::in(['trimestre', 'semestre'])],
            'copier_classes' => ['boolean'],
            'copier_tarifs' => ['boolean'],
            'activer' => ['boolean'],
        ], [
            'libelle.regex' => 'Format attendu : 2026-2027.',
            'libelle.unique' => 'Cette année scolaire existe déjà.',
            'date_fin.after' => 'La date de fin doit être après la date de début.',
        ]);

        [$debut, $fin] = array_map('intval', explode('-', $data['libelle']));
        if ($fin !== $debut + 1) {
            throw ValidationException::withMessages(['libelle' => ['Les deux années doivent se suivre (ex : 2026-2027).']]);
        }

        $annee = DB::transaction(function () use ($data) {
            // Annee de reference pour la copie : la plus recente existante.
            $precedente = AnneeScolaire::orderByDesc('libelle')->first();

            $annee = AnneeScolaire::create([
                'libelle' => $data['libelle'],
                'date_debut' => $data['date_debut'] ?? null,
                'date_fin' => $data['date_fin'] ?? null,
                'is_active' => false,
                'is_cloturee' => false,
            ]);

            $nombre = $data['type_decoupage'] === 'trimestre' ? 3 : 2;
            for ($numero = 1; $numero <= $nombre; $numero++) {
                Periode::create([
                    'annee_scolaire_id' => $annee->id,
                    'type_decoupage' => $data['type_decoupage'],
                    'numero' => $numero,
                    'libelle' => ($numero === 1 ? '1er' : $numero.'eme').' '.$data['type_decoupage'],
                    'is_active' => $numero === 1,
                ]);
            }

            if ($precedente && ($data['copier_classes'] ?? false)) {
                foreach (Classe::where('annee_scolaire_id', $precedente->id)->get() as $classe) {
                    Classe::create([
                        'annee_scolaire_id' => $annee->id,
                        'niveau_id' => $classe->niveau_id,
                        'libelle' => $classe->libelle,
                        'salle' => $classe->salle,
                        'capacite' => $classe->capacite,
                        'langue_vivante_2' => $classe->langue_vivante_2,
                    ]);
                }
            }

            if ($precedente && ($data['copier_tarifs'] ?? false)) {
                foreach (GrilleTarifaire::with('lignes')->where('annee_scolaire_id', $precedente->id)->get() as $grille) {
                    $copie = GrilleTarifaire::create([
                        'annee_scolaire_id' => $annee->id,
                        'niveau_id' => $grille->niveau_id,
                        'affecte' => $grille->affecte,
                        'montant_minimum_inscription' => $grille->montant_minimum_inscription,
                    ]);
                    foreach ($grille->lignes as $ligne) {
                        $copie->lignes()->create(['type_frais_id' => $ligne->type_frais_id, 'montant' => $ligne->montant]);
                    }
                }
            }

            if ($data['activer'] ?? false) {
                $this->rendreActive($annee);
            }

            return $annee;
        });

        return response()->json(['data' => $this->recharger($annee)], 201);
    }

    public function update(Request $request, int $id)
    {
        $this->autoriser($request);

        $annee = AnneeScolaire::findOrFail($id);
        $data = $request->validate([
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after:date_debut'],
        ], ['date_fin.after' => 'La date de fin doit être après la date de début.']);

        $annee->update($data);

        return response()->json(['data' => $this->recharger($annee)]);
    }

    /** Rend l'annee active (proposee par defaut a la connexion). */
    public function activer(Request $request, int $id)
    {
        $this->autoriser($request);

        $annee = AnneeScolaire::findOrFail($id);
        if ($annee->is_cloturee) {
            throw ValidationException::withMessages(['annee' => ['Rouvrez d\'abord cette année : une année clôturée ne peut pas être l\'année en cours.']]);
        }
        DB::transaction(fn () => $this->rendreActive($annee));

        return $this->index($request);
    }

    public function cloturer(Request $request, int $id)
    {
        $this->autoriser($request);

        $annee = AnneeScolaire::findOrFail($id);
        $data = $request->validate(['cloturee' => ['required', 'boolean']]);

        if ($data['cloturee'] && $annee->is_active) {
            throw ValidationException::withMessages(['annee' => ['C\'est l\'année en cours : activez d\'abord la nouvelle année avant de clôturer celle-ci.']]);
        }
        $annee->update(['is_cloturee' => $data['cloturee']]);

        return response()->json(['data' => $this->recharger($annee)]);
    }

    /**
     * Change le decoupage d'une annee deja commencee (trimestres <-> semestres).
     * Les periodes 1 et 2 sont conservees et renommees (leurs notes et
     * moyennes les suivent : 1er trimestre -> 1er semestre) ; la 3e est creee
     * ou supprimee. Refuse si la periode a supprimer porte deja des notes.
     */
    public function changerDecoupage(Request $request, int $id)
    {
        $this->autoriser($request);

        $annee = AnneeScolaire::with(['periodes' => fn ($q) => $q->orderBy('numero')])->findOrFail($id);
        $data = $request->validate(['type_decoupage' => ['required', Rule::in(['trimestre', 'semestre'])]]);
        $type = $data['type_decoupage'];

        if ($annee->is_cloturee) {
            throw ValidationException::withMessages(['type_decoupage' => ['Rouvrez d\'abord cette année pour changer son découpage.']]);
        }
        if ($annee->periodes->isNotEmpty() && $annee->periodes->every(fn (Periode $p) => $p->type_decoupage === $type)) {
            return response()->json(['data' => $this->recharger($annee)]);
        }

        $nombre = $type === 'trimestre' ? 3 : 2;
        $enTrop = $annee->periodes->where('numero', '>', $nombre);
        foreach ($enTrop as $periode) {
            $utilisee = DB::table('notes')->where('periode_id', $periode->id)->exists()
                || DB::table('moyennes')->where('periode_id', $periode->id)->exists()
                || DB::table('moyennes_generales')->where('periode_id', $periode->id)->exists();
            if ($utilisee) {
                throw ValidationException::withMessages(['type_decoupage' => [
                    "Impossible : le {$periode->libelle} contient déjà des notes ou des moyennes.",
                ]]);
            }
        }

        DB::transaction(function () use ($annee, $type, $nombre, $enTrop) {
            $activeSupprimee = $enTrop->contains('is_active', true);
            Periode::whereIn('id', $enTrop->pluck('id'))->delete();

            for ($numero = 1; $numero <= $nombre; $numero++) {
                $libelle = ($numero === 1 ? '1er' : $numero.'eme').' '.$type;
                $periode = $annee->periodes->firstWhere('numero', $numero);
                if ($periode) {
                    $periode->update(['type_decoupage' => $type, 'libelle' => $libelle]);
                } else {
                    Periode::create([
                        'annee_scolaire_id' => $annee->id,
                        'type_decoupage' => $type,
                        'numero' => $numero,
                        'libelle' => $libelle,
                        'is_active' => false,
                    ]);
                }
            }

            // La periode en cours a disparu (3e trimestre) : la derniere devient en cours.
            if ($activeSupprimee || ! Periode::where('annee_scolaire_id', $annee->id)->where('is_active', true)->exists()) {
                Periode::where('annee_scolaire_id', $annee->id)->update(['is_active' => false]);
                Periode::where('annee_scolaire_id', $annee->id)->where('numero', $activeSupprimee ? $nombre : 1)->update(['is_active' => true]);
            }
        });

        return response()->json(['data' => $this->recharger($annee)]);
    }

    public function modifierPeriode(Request $request, int $id)
    {
        $this->autoriser($request);

        $periode = Periode::findOrFail($id);
        $data = $request->validate([
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after:date_debut'],
        ], ['date_fin.after' => 'La date de fin doit être après la date de début.']);
        $periode->update($data);

        return response()->json(['data' => $this->recharger($periode->anneeScolaire)]);
    }

    /** Periode en cours de l'annee (une seule). */
    public function activerPeriode(Request $request, int $id)
    {
        $this->autoriser($request);

        $periode = Periode::findOrFail($id);
        if ($periode->is_cloturee) {
            throw ValidationException::withMessages(['periode' => ['Rouvrez d\'abord cette période.']]);
        }
        DB::transaction(function () use ($periode) {
            Periode::where('annee_scolaire_id', $periode->annee_scolaire_id)->update(['is_active' => false]);
            $periode->update(['is_active' => true]);
        });

        return response()->json(['data' => $this->recharger($periode->anneeScolaire)]);
    }

    /** Mettre fin a une periode : reserve au directeur (notes.arreter), arrete toutes les notes. */
    public function cloturerPeriode(Request $request, int $id)
    {
        abort_unless($request->user()->can('notes.arreter'), 403, 'Seul le directeur peut clôturer ou rouvrir une période (droit « Arrêter les notes »).');

        $periode = Periode::findOrFail($id);
        $data = $request->validate(['cloturee' => ['required', 'boolean']]);
        if ($data['cloturee'] && ! $periode->is_cloturee) {
            ArretNotes::cloturer($periode, $request->user()->id);
        } else {
            $periode->update(['is_cloturee' => $data['cloturee']]);
        }

        return response()->json(['data' => $this->recharger($periode->anneeScolaire)]);
    }

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()->can('annees_scolaires.gerer'), 403);
    }

    private function rendreActive(AnneeScolaire $annee): void
    {
        AnneeScolaire::whereKeyNot($annee->id)->update(['is_active' => false]);
        $annee->update(['is_active' => true]);
    }

    private function recharger(AnneeScolaire $annee): array
    {
        return $this->presenter(
            AnneeScolaire::with(['periodes' => fn ($q) => $q->orderBy('numero')])->withCount(['classes', 'inscriptions'])->findOrFail($annee->id)
        );
    }

    private function presenter(AnneeScolaire $a): array
    {
        return [
            'id' => $a->id,
            'libelle' => $a->libelle,
            'date_debut' => $a->date_debut?->toDateString(),
            'date_fin' => $a->date_fin?->toDateString(),
            'is_active' => (bool) $a->is_active,
            'is_cloturee' => (bool) $a->is_cloturee,
            'nombre_classes' => (int) $a->classes_count,
            'nombre_inscriptions' => (int) $a->inscriptions_count,
            'type_decoupage' => $a->periodes->first()?->type_decoupage,
            'periodes' => $a->periodes->map(fn (Periode $p) => [
                'id' => $p->id,
                'numero' => $p->numero,
                'libelle' => $p->libelle,
                'date_debut' => $p->date_debut?->toDateString(),
                'date_fin' => $p->date_fin?->toDateString(),
                'is_active' => (bool) $p->is_active,
                'is_cloturee' => (bool) $p->is_cloturee,
            ])->values(),
        ];
    }
}
