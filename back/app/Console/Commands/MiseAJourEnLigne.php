<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\InscriptionController;
use App\Models\AnneeScolaire;
use App\Support\ScanEnLigne;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionRegistrar;

/**
 * « Mettre a jour » (Inscriptions) en arriere-plan : scanne sur le site de
 * l'Etat les inscriptions de l'annee pas encore trouvees en ligne, une par
 * une, et tient l'etat a jour pour l'ecran (App\Support\ScanEnLigne). Lancee
 * par l'application (POST inscriptions/en-ligne/mise-a-jour/lancer) : elle
 * continue si l'utilisateur change de page ou ferme l'application.
 */
class MiseAJourEnLigne extends Command
{
    protected $signature = 'inscriptions:mise-a-jour-en-ligne
        {etablissement : id de l\'etablissement}
        {annee : id de l\'annee scolaire}
        {--user= : compte qui a lance la mise a jour}
        {--niveaux= : niveaux du poste (educateur), separes par des virgules ; vide = tous}';

    protected $description = 'Met a jour les inscriptions depuis l\'inscription en ligne (site de l\'Etat), en arriere-plan';

    public function handle(TenantContext $tenant): int
    {
        $etab = (int) $this->argument('etablissement');
        $anneeId = (int) $this->argument('annee');
        $tenant->set($etab);
        $tenant->setAnneeScolaire($anneeId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($etab);
        $annee = AnneeScolaire::findOrFail($anneeId);
        $niveaux = $this->option('niveaux') !== null && $this->option('niveaux') !== ''
            ? array_map('intval', explode(',', $this->option('niveaux'))) : null;
        $userId = $this->option('user') ? (int) $this->option('user') : null;

        $controleur = app(InscriptionController::class);
        $requete = fn () => InscriptionController::inscriptionsAVerifier($anneeId, $niveaux);
        $etat = ScanEnLigne::lire($etab, $anneeId);
        $etat = array_merge($etat, ['en_cours' => true, 'total' => $requete()->count(), 'traitees' => 0, 'trouvees' => 0,
            'introuvables' => 0, 'erreur' => null, 'debut' => now()->toDateTimeString(), 'fin' => null, 'dernieres' => []]);
        ScanEnLigne::ecrire($etab, $anneeId, $etat);

        $apresId = 0;
        try {
            while (true) {
                $lot = $requete()->where('inscriptions.id', '>', $apresId)->orderBy('inscriptions.id')->limit(20)->get();
                if ($lot->isEmpty()) {
                    break;
                }
                foreach ($lot as $inscription) {
                    if (ScanEnLigne::arretDemande($etab, $anneeId)) {
                        $etat['erreur'] = 'Mise à jour arrêtée.';
                        break 2;
                    }
                    $ligne = $controleur->traiterEnLigne($inscription, $annee->libelle, $tenant, $userId);
                    if ($ligne['erreur']) {
                        // Site de l'Etat en panne : on s'arrete, rien n'est perdu.
                        $etat['erreur'] = $ligne['erreur'];
                        break 2;
                    }
                    $etat['traitees']++;
                    $etat['trouvees'] += $ligne['trouve'] ? 1 : 0;
                    $etat['introuvables'] += $ligne['introuvable'] ? 1 : 0;
                    $etat['dernieres'][] = array_intersect_key($ligne, array_flip(['id', 'eleve', 'trouve', 'introuvable', 'remarques']));
                    $etat['total'] = max($etat['total'], $etat['traitees']);
                    ScanEnLigne::ecrire($etab, $anneeId, $etat);
                    $apresId = $inscription->id;
                }
            }
        } catch (\Throwable $e) {
            $etat['erreur'] = 'Mise à jour interrompue : '.$e->getMessage();
            report($e);
        } finally {
            $etat['en_cours'] = false;
            $etat['fin'] = now()->toDateTimeString();
            ScanEnLigne::ecrire($etab, $anneeId, $etat);
            ScanEnLigne::effacerArret($etab, $anneeId);
        }
        $this->info("{$etat['traitees']} inscription(s) scannée(s) : {$etat['trouvees']} trouvée(s), {$etat['introuvables']} introuvable(s).");

        return self::SUCCESS;
    }
}
