<?php

namespace App\Support;

use App\Models\AnneeScolaire;
use App\Models\TypeFrais;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Point (bilan) des paiements d'un caissier sur une periode : montant encaisse
 * par type de frais et par statut de l'eleve (affecte / non affecte).
 * Lignes : frais actifs (annexes, puis inscription, puis scolarite, ordre des
 * types de frais) + frais inactifs ayant recu un paiement + dettes des annees
 * precedentes s'il y en a ; ligne et colonne de totaux.
 */
class BilanCaisse
{
    private const MOIS = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    /**
     * Periode demandee : jour / dates (du, au) / mois (AAAA-MM) / annee.
     *
     * @return array{periode: string, du: ?string, au: ?string, mois: ?string, libelle: string}
     */
    public static function periode(array $filtres, ?AnneeScolaire $annee): array
    {
        $periode = $filtres['periode'] ?? 'jour';
        $formater = fn (string $d) => CarbonImmutable::parse($d)->format('d/m/Y');

        switch ($periode) {
            case 'annee':
                return ['periode' => 'annee', 'du' => null, 'au' => null, 'mois' => null, 'libelle' => 'l\'année scolaire '.($annee?->libelle ?? '')];
            case 'mois':
                if (! preg_match('/^(\d{4})-(\d{2})$/', $filtres['mois'] ?? '', $m)) {
                    throw ValidationException::withMessages(['mois' => ['Choisissez un mois.']]);
                }
                $debut = CarbonImmutable::create((int) $m[1], (int) $m[2], 1);

                return ['periode' => 'mois', 'du' => $debut->toDateString(), 'au' => $debut->endOfMonth()->toDateString(), 'mois' => $filtres['mois'],
                    'libelle' => mb_strtolower(self::MOIS[(int) $m[2]]).' '.$m[1]];
            case 'dates':
                $du = $filtres['du'] ?? null;
                $au = $filtres['au'] ?? $du;
                if (! $du) {
                    throw ValidationException::withMessages(['du' => ['Saisissez la date de début.']]);
                }
                if ($au < $du) {
                    throw ValidationException::withMessages(['au' => ['La date de fin doit suivre la date de début.']]);
                }

                return ['periode' => 'dates', 'du' => $du, 'au' => $au, 'mois' => null,
                    'libelle' => $du === $au ? 'la journée du '.$formater($du) : 'la période du '.$formater($du).' au '.$formater($au)];
            default:
                $jour = today()->toDateString();

                return ['periode' => 'jour', 'du' => $jour, 'au' => $jour, 'mois' => null, 'libelle' => 'la journée du '.$formater($jour)];
        }
    }

    /** Cycles proposes (niveaux.cycle). */
    public const CYCLES = ['college' => 'Premier cycle', 'lycee' => 'Second cycle', 'primaire' => 'Primaire', 'maternelle' => 'Maternelle'];

    /**
     * Criteres eleve du bilan : sexe (M/F), redoublant (0/1) et UN seul parmi
     * cycle / niveau / classe (portent sur l'inscription reglee).
     *
     * @return array{sexe: ?string, redoublant: ?bool, cycle: ?string, niveau_id: ?int, classe_id: ?int}
     */
    public static function criteres(array $filtres): array
    {
        $c = [
            'sexe' => in_array($filtres['sexe'] ?? null, ['M', 'F'], true) ? $filtres['sexe'] : null,
            'redoublant' => isset($filtres['redoublant']) && $filtres['redoublant'] !== '' && $filtres['redoublant'] !== null
                ? filter_var($filtres['redoublant'], FILTER_VALIDATE_BOOLEAN) : null,
            'cycle' => array_key_exists($filtres['cycle'] ?? '', self::CYCLES) ? $filtres['cycle'] : null,
            'niveau_id' => ! empty($filtres['niveau_id']) ? (int) $filtres['niveau_id'] : null,
            'classe_id' => ! empty($filtres['classe_id']) ? (int) $filtres['classe_id'] : null,
        ];
        if (count(array_filter([$c['cycle'], $c['niveau_id'], $c['classe_id']])) > 1) {
            throw ValidationException::withMessages(['cycle' => ['Choisissez un cycle, un niveau ou une classe, pas plusieurs.']]);
        }

        return $c;
    }

    /** Filtre une requete sur reglements (jointe) selon les criteres eleve. */
    public static function appliquerCriteres($requete, array $c)
    {
        if (! array_filter($c, fn ($v) => $v !== null)) {
            return $requete;
        }
        $requete->join('inscriptions as ic', 'ic.id', '=', 'reglements.inscription_id');
        if ($c['sexe']) {
            $requete->join('eleves as ec', 'ec.id', '=', 'reglements.eleve_id')->where('ec.sexe', $c['sexe']);
        }
        if ($c['redoublant'] !== null) {
            $requete->where('ic.redoublant', $c['redoublant']);
        }
        if ($c['classe_id']) {
            $requete->where('ic.classe_id', $c['classe_id']);
        } elseif ($c['niveau_id']) {
            $requete->where('ic.niveau_id', $c['niveau_id']);
        } elseif ($c['cycle']) {
            $requete->whereIn('ic.niveau_id', DB::table('niveaux')->where('cycle', $c['cycle'])->select('id'));
        }

        return $requete;
    }

    /** "filles · redoublants · 6EME A" (vide sans critere). */
    public static function libelleCriteres(array $c): string
    {
        return implode(' · ', array_filter([
            $c['sexe'] ? ($c['sexe'] === 'F' ? 'filles' : 'garçons') : null,
            $c['redoublant'] !== null ? ($c['redoublant'] ? 'redoublants' : 'non redoublants') : null,
            $c['cycle'] ? mb_strtolower(self::CYCLES[$c['cycle']]) : null,
            $c['niveau_id'] ? DB::table('niveaux')->where('id', $c['niveau_id'])->value('libelle') : null,
            $c['classe_id'] ? DB::table('classes')->where('id', $c['classe_id'])->value('libelle') : null,
        ]));
    }

    /** Listes du filtre : cycles, niveaux et classes de l'annee. */
    /** @param  ?array<int>  $niveaux  niveaux autorises (educateur), null = tous */
    public static function criteresDisponibles(int $anneeId, ?array $niveaux = null): array
    {
        $classes = DB::table('classes')->join('niveaux', 'niveaux.id', '=', 'classes.niveau_id')
            ->where('classes.etablissement_id', app(TenantContext::class)->id())
            ->where('classes.annee_scolaire_id', $anneeId)
            ->when($niveaux !== null, fn ($q) => $q->whereIn('classes.niveau_id', $niveaux))
            ->orderBy('niveaux.ordre')->orderBy('classes.libelle')
            ->get(['classes.id', 'classes.libelle', 'classes.niveau_id', 'niveaux.libelle as niveau', 'niveaux.cycle', 'niveaux.ordre']);

        return [
            'cycles' => $classes->pluck('cycle')->unique()->sortBy(fn ($c) => array_search($c, array_keys(self::CYCLES), true))
                ->map(fn ($c) => ['valeur' => $c, 'libelle' => self::CYCLES[$c]])->values(),
            'niveaux' => $classes->unique('niveau_id')->map(fn ($c) => ['id' => $c->niveau_id, 'libelle' => $c->niveau, 'cycle' => $c->cycle])->values(),
            'classes' => $classes->map(fn ($c) => ['id' => $c->id, 'libelle' => $c->libelle, 'niveau_id' => $c->niveau_id])->values(),
        ];
    }

    /**
     * Bilan general : 1. general, 2. par genre, 3. redoublement, 4. par cycle,
     * 5. par niveau, 6. par classe ; chaque sous-bilan ajoute son critere a
     * ceux choisis. Un critere deja choisi limite sa section (ex. filles : 2.1
     * seulement ; classe 6EME A : son cycle, son niveau et sa classe).
     *
     * @return list<array{numero: string, titre: string, sous: list<array{numero: string, titre: string, bilan: array}>}>
     */
    public static function general(int $anneeId, int $caissierId, array $periode, array $criteres): array
    {
        $c = self::criteres($criteres);
        $dispo = self::criteresDisponibles($anneeId);
        $niveaux = collect($dispo['niveaux']);
        $classes = collect($dispo['classes']);

        // Perimetre cycle / niveau / classe impose par le critere choisi.
        if ($c['classe_id']) {
            $classes = $classes->where('id', $c['classe_id']);
            $niveaux = $niveaux->whereIn('id', $classes->pluck('niveau_id'));
        } elseif ($c['niveau_id']) {
            $niveaux = $niveaux->where('id', $c['niveau_id']);
            $classes = $classes->whereIn('niveau_id', $niveaux->pluck('id'));
        } elseif ($c['cycle']) {
            $niveaux = $niveaux->where('cycle', $c['cycle']);
            $classes = $classes->whereIn('niveau_id', $niveaux->pluck('id'));
        }
        $cycles = collect($dispo['cycles'])->whereIn('valeur', $niveaux->pluck('cycle'));

        $calcul = fn (array $plus) => self::calculer($anneeId, $caissierId, $periode, array_merge($c, $plus));
        $section = function (string $numero, string $titre, array $elements) use ($calcul) {
            $sous = [];
            foreach (array_values($elements) as $i => [$libelle, $plus]) {
                $sous[] = ['numero' => $numero.'.'.($i + 1), 'titre' => $libelle, 'bilan' => $calcul($plus)];
            }

            return ['numero' => $numero, 'titre' => $titre, 'sous' => $sous];
        };
        // Pour cycle / niveau / classe, le critere de la section remplace celui choisi (meme perimetre).
        $sansPerimetre = ['cycle' => null, 'niveau_id' => null, 'classe_id' => null];

        return [
            ['numero' => '1', 'titre' => 'Bilan général', 'sous' => [['numero' => '1', 'titre' => 'Bilan général', 'bilan' => $calcul([])]]],
            $section('2', 'Bilan par genre', array_filter([
                $c['sexe'] !== 'M' ? ['Bilan filles', ['sexe' => 'F']] : null,
                $c['sexe'] !== 'F' ? ['Bilan garçons', ['sexe' => 'M']] : null,
            ])),
            $section('3', 'Bilan par redoublement', array_filter([
                $c['redoublant'] !== true ? ['Bilan non redoublants', ['redoublant' => false]] : null,
                $c['redoublant'] !== false ? ['Bilan redoublants', ['redoublant' => true]] : null,
            ])),
            $section('4', 'Bilan par cycle', $cycles->map(fn ($x) => ['Bilan '.mb_strtolower($x['libelle']), ['cycle' => $x['valeur']] + $sansPerimetre])->all()),
            $section('5', 'Bilan par niveau', $niveaux->map(fn ($x) => ['Bilan '.$x['libelle'], ['niveau_id' => $x['id']] + $sansPerimetre])->all()),
            $section('6', 'Bilan par classe', $classes->map(fn ($x) => ['Bilan '.$x['libelle'], ['classe_id' => $x['id']] + $sansPerimetre])->all()),
        ];
    }

    /** Mois de l'annee scolaire (liste deroulante), du debut a la fin. */
    public static function moisDeLAnnee(?AnneeScolaire $annee): array
    {
        if (! $annee) {
            return [];
        }
        [$a1, $a2] = array_map('intval', explode('-', $annee->libelle.'-'));
        $debut = CarbonImmutable::parse($annee->date_debut ?? "{$a1}-09-01")->startOfMonth();
        $fin = CarbonImmutable::parse($annee->date_fin ?? ($a2 ?: $a1 + 1).'-07-31')->startOfMonth();
        $liste = [];
        for ($m = $debut; $m <= $fin; $m = $m->addMonth()) {
            $liste[] = ['valeur' => $m->format('Y-m'), 'libelle' => self::MOIS[$m->month].' '.$m->year];
        }

        return $liste;
    }

    /**
     * @return array{lignes: list<array>, totaux: array{affecte: int, non_affecte: int, total: int}, nombre_paiements: int}
     */
    public static function calculer(int $anneeId, int $caissierId, array $periode, array $criteres = []): array
    {
        $criteres = self::criteres($criteres);
        $base = fn () => self::appliquerCriteres(DB::table('reglement_lignes')
            ->join('reglements', 'reglements.id', '=', 'reglement_lignes.reglement_id')
            ->where('reglements.etablissement_id', app(TenantContext::class)->id())
            ->where('reglements.annee_scolaire_id', $anneeId)
            ->where('reglements.caissier_id', $caissierId)
            ->when($periode['du'], fn ($q, $d) => $q->whereDate('reglements.date_paiement', '>=', $d))
            ->when($periode['au'], fn ($q, $d) => $q->whereDate('reglements.date_paiement', '<=', $d)), $criteres);

        // Frais : statut de l'inscription a laquelle appartient le frais.
        $parFrais = $base()
            ->join('frais_eleves', 'frais_eleves.id', '=', 'reglement_lignes.frais_eleve_id')
            ->join('inscriptions', 'inscriptions.id', '=', 'frais_eleves.inscription_id')
            ->groupBy('frais_eleves.type_frais_id', 'inscriptions.affecte')
            ->selectRaw('frais_eleves.type_frais_id AS type, inscriptions.affecte AS affecte, SUM(reglement_lignes.montant) AS montant')
            ->get();
        // Dettes : statut de l'inscription reglee avec le versement.
        $parDette = $base()
            ->whereNotNull('reglement_lignes.dette_id')
            ->leftJoin('inscriptions', 'inscriptions.id', '=', 'reglements.inscription_id')
            ->groupBy('inscriptions.affecte')
            ->selectRaw('COALESCE(inscriptions.affecte, 0) AS affecte, SUM(reglement_lignes.montant) AS montant')
            ->get();
        $nombre = $base()->distinct()->count('reglements.id');

        $montant = fn ($lignes, bool $affecte, ?int $type = null) => (int) $lignes
            ->filter(fn ($l) => (bool) $l->affecte === $affecte && ($type === null || (int) $l->type === $type))
            ->sum('montant');

        $types = TypeFrais::where('nature', '!=', 'en_nature')->get()
            ->filter(fn (TypeFrais $t) => $t->is_active || $parFrais->contains(fn ($l) => (int) $l->type === $t->id))
            ->sortBy(fn (TypeFrais $t) => [match ($t->nature) { 'annexe' => 0, 'inscription' => 1, 'scolarite' => 2, default => 3 }, $t->ordre, $t->id]);

        $lignes = $types->map(fn (TypeFrais $t) => [
            'cle' => 'frais-'.$t->id,
            'type_frais_id' => $t->id,
            'dette' => false,
            'libelle' => $t->libelle,
            'nature' => $t->nature,
            'actif' => (bool) $t->is_active,
            'applicable_affecte' => (bool) $t->applicable_affecte,
            'applicable_non_affecte' => (bool) $t->applicable_non_affecte,
            'affecte' => $montant($parFrais, true, $t->id),
            'non_affecte' => $montant($parFrais, false, $t->id),
        ])->values();

        if ($parDette->sum('montant') > 0) {
            $lignes->push([
                'cle' => 'dettes',
                'type_frais_id' => null,
                'dette' => true,
                'libelle' => 'Dettes des années précédentes',
                'nature' => 'dette',
                'actif' => true,
                'applicable_affecte' => true,
                'applicable_non_affecte' => true,
                'affecte' => $montant($parDette, true),
                'non_affecte' => $montant($parDette, false),
            ]);
        }

        $lignes = $lignes->map(fn ($l) => $l + ['total' => $l['affecte'] + $l['non_affecte']]);

        return [
            'lignes' => $lignes->all(),
            'totaux' => [
                'affecte' => (int) $lignes->sum('affecte'),
                'non_affecte' => (int) $lignes->sum('non_affecte'),
                'total' => (int) $lignes->sum('total'),
            ],
            'nombre_paiements' => $nombre,
        ];
    }
}
