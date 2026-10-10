<?php

namespace App\Console\Commands;

use App\Models\Etablissement;
use App\Models\Inscription;
use App\Models\User;
use App\Services\EtablissementProvisioningService;
use App\Support\MigrationV1\BaseApplication;
use App\Support\MigrationV1\Conversions as C;
use App\Support\NomClasse;
use App\Support\Synchro\Tables;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reprise des donnees d'une ecole V1 dans une base V2 (MIGRATION_V1_V2.md).
 *
 * Lit une copie de la base V1 (schema MySQL a part, jamais modifie) et ecrit
 * dans la base cible indiquee, jamais dans la base de l'application (.env).
 * Tout se fait dans une transaction : en mode --essai, elle est annulee et
 * seul le rapport reste. Rapport et mots de passe provisoires :
 * storage/app/private/migration-v1/ (hors depot).
 *
 * Evolutions V2 prises en compte (06/10/2026) :
 * - E1 : niveaux avec serie (2ndeA, TleA2...) rattaches au niveau general ;
 *   classes, inscriptions et coefficients sur la serie, grilles et
 *   educateurs sur le niveau general ; libelles des niveaux de NiveauSeeder ;
 * - noms des classes : niveau + numero en exposant (NomClasse : « TleA2³ ») ;
 * - E3 : connexion par telephone (telephones normalises a 10 chiffres) ;
 * - billet d'entree : V1 etablissement.date_expiration = date d'echeance
 *   (parametre date_validite_billet), jour_expire_inscr = nombre de jours.
 * Limites : caisse fictive (E6) notee dans la correspondance seulement ;
 * composantes du francais (E2) creees comme matieres.
 */
class MigrationV1 extends Command
{
    protected $signature = 'migration:v1
        {--cible= : base V2 de destination (jamais celle du .env)}
        {--source=monde_edu_v1_source : base MySQL qui contient la copie de la V1}
        {--charger-source= : fichiers .sql V1 a charger d\'abord dans la base source (separes par des virgules)}
        {--preparer-cible : vide toutes les tables de la base cible (nom contenant « migration » ou « test » ; creee si besoin), la met a jour et pose les referentiels}
        {--copier-v2= : base V2 a copier dans la cible (mysqldump, lecture seule) ; la cible (nom contenant « migration ») est recreee puis migree}
        {--activer : apres une reprise reussie, l\'application (.env DB_DATABASE) utilise la base cible}
        {--compte= : id du compte (base de l\'application) recopie dans la cible comme Super admin de l\'ecole reprise, pour pouvoir s\'y connecter}
        {--mysql-bin= : dossier de mysql et mysqldump (par defaut : PATH, puis C:/laragon/bin/mysql/*/bin)}
        {--images= : dossier des images de l\'ecole (logoEcole, amoirie, photoEleve) ; par defaut storage/app/public/logos}
        {--finances-depuis=2021 : premiere annee dont les finances sont reprises (decision Q1)}
        {--regroupements= : CSV des comptes V1 a regrouper (une ligne = mat_admin du compte principal ; autres mat_admin), sinon regroupement automatique par nom et prenoms}
        {--essai : tout faire puis annuler (rapport seulement)}';

    protected $description = 'Reprend les données d\'une école V1 dans une base V2 (premier test de migration)';

    private int $etab;

    private int $financesDepuis;

    /** @var array<string, array{libelle: string, nombre: int, exemples: list<string>}> */
    private array $anomalies = [];

    /** @var list<array> lignes de migration_v1_correspondances */
    private array $correspondances = [];

    /** @var array<string, int> compteurs du rapport */
    private array $compteurs = [];

    private array $controles = [];

    private array $motsDePasse = [];

    /** Compte de l'application recopie dans la cible (option --compte). */
    private ?object $compte = null;

    private ?string $fichierMotsDePasse = null;

    // Correspondances V1 -> V2 en memoire
    private array $annees = [];          // an V1 => annee_scolaire_id

    private array $niveaux = [];         // code V2 => niveau_id

    private array $matieres = [];        // code V2 => matiere_id

    private array $matieresV1 = [];      // matiere.id_mat V1 => matiere_id V2

    private array $typesFrais = [];      // code V2 => [id, ordre, nature]

    private array $typesReduction = [];  // code => id

    private array $users = [];           // mat_admin => user_id

    private array $personnels = [];      // mat_admin => personnel_id

    private array $usersParNom = [];     // cle nom => user_id

    private array $rolesV1 = [];         // mat_admin => role_code V1

    private ?int $userReprise = null;

    private array $classes = [];         // code_classe => classe_id

    private array $eleves = [];          // matricule => eleve_id

    private array $inscriptions = [];    // id_inscr V1 => [id V2, an, eleve_id]

    private array $frais = [];           // inscription_id V2 => [code type V2 => frais_eleve_id]

    private array $dettes = [];          // id_dette V1 => dette_id

    private array $dettesParEleveAnnee = []; // eleve_id|an origine => dette_id

    public function handle(): int
    {
        $principale = config('database.connections.mysql.database');
        $cible = (string) $this->option('cible');
        $source = (string) $this->option('source');
        $this->financesDepuis = (int) $this->option('finances-depuis');

        if ($cible === '' || $cible === $principale || $cible === $source) {
            $this->error("Indiquez --cible : une base différente de celle de l'application ($principale) et de la source.");

            return self::FAILURE;
        }
        if ($this->option('preparer-cible') && ! preg_match('/migration|test/i', $cible)) {
            $this->error('--preparer-cible vide la base : son nom doit contenir « migration » ou « test ».');

            return self::FAILURE;
        }
        $v2 = (string) $this->option('copier-v2');
        if ($v2 !== '' && ($this->option('preparer-cible') || ! preg_match('/migration/i', $cible) || $v2 === $cible)) {
            $this->error('--copier-v2 recrée la cible : son nom doit contenir « migration » et différer de la base copiée (sans --preparer-cible).');

            return self::FAILURE;
        }
        if ($this->option('activer') && $this->option('essai')) {
            $this->error('--activer ne s\'utilise pas avec --essai.');

            return self::FAILURE;
        }
        if ($source === $principale || ! preg_match('/v1/i', $source)) {
            $this->error('La base source doit être une copie de la V1 (nom contenant « v1 »), jamais la base de l\'application.');

            return self::FAILURE;
        }

        $debut = microtime(true);
        config(['database.connections.v1' => array_merge(config('database.connections.mysql'), ['database' => $source])]);

        if ($fichiers = $this->option('charger-source')) {
            $this->chargerSource($source, array_map('trim', explode(',', $fichiers)));
        }

        if ($this->option('preparer-cible')) {
            $this->info("Préparation de la base cible {$cible}…");
            DB::statement("CREATE DATABASE IF NOT EXISTS `$cible` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        if ($id = $this->option('compte')) {
            $this->compte = DB::table('users')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $this->compte || ! $this->compte->email) {
                $this->error("Compte $id introuvable ou sans e-mail dans $principale.");

                return self::FAILURE;
            }
        }
        if ($v2 !== '') {
            $this->copierV2($v2, $cible);
        }
        $this->basculerVers($cible);
        if ($v2 !== '' && Artisan::call('migrate', ['--force' => true]) !== 0) {
            $this->error('migrate a échoué sur la copie : '.Artisan::output());

            return self::FAILURE;
        }
        if ($this->option('preparer-cible')) {
            // Structure a jour, puis toutes les tables videes (la base et ses tables restent).
            if (Artisan::call('migrate', ['--force' => true]) !== 0) {
                $this->error('migrate a échoué : '.Artisan::output());

                return self::FAILURE;
            }
            $this->viderTables($cible);
            foreach ([['db:seed', ['--class' => 'Database\\Seeders\\PermissionSeeder']], ['db:seed', ['--class' => 'Database\\Seeders\\NiveauSeeder']]] as [$commande, $arguments]) {
                if (Artisan::call($commande, $arguments + ['--force' => true]) !== 0) {
                    $this->error("$commande a échoué : ".Artisan::output());

                    return self::FAILURE;
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ((int) DB::selectOne('SELECT @@auto_increment_increment AS i')->i !== 1) {
            $this->error('auto_increment_increment doit valoir 1.');

            return self::FAILURE;
        }
        $codeEtab = DB::connection('v1')->table('etablissement')->value('code_etab');
        if (DB::table('etablissements')->where('code', $codeEtab)->exists()) {
            $this->error("L'établissement $codeEtab existe déjà dans $cible : relancez avec --copier-v2 (recopie de la V2) ou --preparer-cible (base vide).");

            return self::FAILURE;
        }
        $this->creerTableCorrespondances();

        DB::statement('SET @sync_application = 1');
        DB::beginTransaction();
        try {
            $this->etape('Établissement', fn () => $this->etablissement());
            $this->etape('Années et périodes', fn () => $this->anneesEtPeriodes());
            $this->etape('Matières, coefficients, types de frais', fn () => $this->referentiels());
            $this->etape('Personnel', fn () => $this->personnel());
            if ($this->compte) {
                $this->etape('Compte administrateur', fn () => $this->compteAdministrateur());
            }
            $this->etape('Classes et affectations', fn () => $this->classesEtAffectations());
            $this->etape('Grilles tarifaires', fn () => $this->grilles());
            $this->etape('Élèves et tuteurs', fn () => $this->elevesEtTuteurs());
            $this->etape('Inscriptions', fn () => $this->inscriptionsV1());
            $this->etape('Dettes', fn () => $this->dettesV1());
            $this->etape('Frais dus et réductions', fn () => $this->fraisEtReductions());
            $this->etape('Règlements', fn () => $this->reglements());
            $this->etape('Recalcul des montants', fn () => $this->recalculer());
            $this->etape('Dépenses', fn () => $this->depenses());
            $this->etape('SMS', fn () => $this->sms());
            $this->etape('Correspondances', fn () => $this->enregistrerCorrespondances());
            $this->etape('Contrôles', fn () => $this->controler());

            if ($this->option('essai')) {
                DB::rollBack();
                $this->warn('Mode essai : tout a été annulé.');
            } else {
                DB::commit();
                $this->etape('Images', fn () => $this->images());
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Échec, rien n\'a été enregistré : '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')');
            $this->ecrireRapport($source, $cible, $debut, $e);

            return self::FAILURE;
        } finally {
            DB::statement('SET @sync_application = NULL');
        }

        $chemin = $this->ecrireRapport($source, $cible, $debut);
        $this->info("Rapport : $chemin");
        if ($this->option('activer')) {
            $this->activer($cible);
        }

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ Preparation

    /** Charge les dumps V1 dans la base source (instruction par instruction). */
    private function chargerSource(string $source, array $fichiers): void
    {
        DB::statement("CREATE DATABASE IF NOT EXISTS `$source` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
        DB::purge('v1');
        $v1 = DB::connection('v1');
        foreach ($fichiers as $fichier) {
            if (! is_file($fichier)) {
                throw new \RuntimeException("Fichier introuvable : $fichier");
            }
            $this->info('Chargement de '.basename($fichier).' dans '.$source.'…');
            $sql = preg_replace('/^--.*$/m', '', str_replace("\r\n", "\n", file_get_contents($fichier)));
            foreach (preg_split('/;\n/', $sql) as $instruction) {
                if (trim($instruction) !== '') {
                    $v1->unprepared($instruction);
                }
            }
        }
    }

    /**
     * Copie la base V2 dans la cible : mysqldump de la V2 (lecture seule,
     * garde comme sauvegarde) puis chargement dans la cible recreee.
     */
    private function copierV2(string $v2, string $cible): void
    {
        $bin = $this->dossierMysql();
        $dossier = storage_path('app/private/migration-v1');
        if (! is_dir($dossier)) {
            mkdir($dossier, 0775, true);
        }
        $c = config('database.connections.mysql');
        $acces = sprintf('--host=%s --port=%s --user=%s%s --default-character-set=utf8mb4',
            $c['host'], $c['port'], $c['username'], (string) $c['password'] !== '' ? ' --password='.escapeshellarg($c['password']) : '');
        $fichier = $dossier.DIRECTORY_SEPARATOR.'sauvegarde-'.$v2.'-'.now()->format('Ymd-His').'.sql';

        $this->info("Sauvegarde de $v2 (lecture seule) : $fichier");
        $dump = Process::forever()->run(sprintf('"%s" %s --single-transaction --routines --triggers --events --hex-blob %s > "%s"',
            $bin.DIRECTORY_SEPARATOR.'mysqldump', $acces, $v2, $fichier));
        if (! $dump->successful() || ! is_file($fichier) || filesize($fichier) < 1000) {
            throw new \RuntimeException("mysqldump de $v2 a échoué : ".$dump->errorOutput());
        }

        $this->info("Copie dans {$cible}…");
        DB::statement("DROP DATABASE IF EXISTS `$cible`");
        DB::statement("CREATE DATABASE `$cible` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $import = Process::forever()->run(sprintf('"%s" %s %s < "%s"', $bin.DIRECTORY_SEPARATOR.'mysql', $acces, $cible, $fichier));
        if (! $import->successful()) {
            throw new \RuntimeException("Chargement de la copie dans $cible échoué : ".$import->errorOutput());
        }
        $this->compteurs['Copie de la V2'] = "$v2 → $cible (sauvegarde ".basename($fichier).')';
    }

    private function dossierMysql(): string
    {
        if ($bin = $this->option('mysql-bin')) {
            return rtrim($bin, '/\\');
        }
        $trouve = Process::run(PHP_OS_FAMILY === 'Windows' ? 'where mysqldump' : 'command -v mysqldump');
        if ($trouve->successful() && trim($trouve->output()) !== '') {
            return dirname(trim(strtok($trouve->output(), "\r\n")));
        }
        $trouves = glob('C:/laragon/bin/mysql/*/bin/mysqldump.exe') ?: [];
        if (! $trouves) {
            throw new \RuntimeException('mysqldump introuvable : indiquez --mysql-bin.');
        }
        sort($trouves);

        return str_replace('/', DIRECTORY_SEPARATOR, dirname(end($trouves)));
    }

    /** L'application utilise desormais la base cible (.env DB_DATABASE), ancien .env garde. */
    private function activer(string $cible): void
    {
        BaseApplication::basculer($cible);
        $this->info("L'application utilise maintenant la base $cible (.env DB_DATABASE ; ancien .env gardé dans storage/app/private/migration-v1/).");
    }

    /** Vide toutes les tables de la base (sauf l'historique des migrations), sans les supprimer. */
    private function viderTables(string $base): void
    {
        $tables = DB::table('information_schema.tables')->where('table_schema', $base)->where('table_type', 'BASE TABLE')
            ->where('table_name', '!=', 'migrations')->selectRaw('table_name as nom')->pluck('nom');
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            DB::statement("TRUNCATE TABLE `$table`");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        $this->info(count($tables)." tables vidées dans $base.");
    }

    private function basculerVers(string $cible): void
    {
        config(['database.connections.mysql.database' => $cible]);
        DB::purge('mysql');
        DB::reconnect('mysql');
        Tables::oublier();
    }

    private function creerTableCorrespondances(): void
    {
        if (Schema::hasTable('migration_v1_correspondances')) {
            return;
        }
        Schema::create('migration_v1_correspondances', function ($t) {
            $t->id();
            $t->foreignId('etablissement_id');
            $t->string('table_v1', 40);
            $t->string('cle_v1', 60);
            $t->string('table_v2', 40);
            $t->unsignedBigInteger('id_v2')->nullable();
            $t->json('infos')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['etablissement_id', 'table_v1', 'cle_v1'], 'migration_v1_corresp_cle_index');
        });
    }

    // ------------------------------------------------------------------ Etapes

    private function etablissement(): void
    {
        $e = DB::connection('v1')->table('etablissement')->first();
        $info = Schema::connection('v1')->hasTable('info_etablissement')
            ? DB::connection('v1')->table('info_etablissement')->where('code_etab', $e->code_etab)->first() : null;
        $type = strtolower(trim((string) ($info->type_etab ?? $e->type_etab)));
        $pays = C::texte($e->pays ?? $info->pays ?? null);

        $this->etab = DB::table('etablissements')->insertGetId([
            'code' => $e->code_etab,
            'nom' => C::texte($e->nom_etab),
            'type_etablissement' => in_array($type, ['maternelle', 'primaire', 'college', 'lycee', 'superieur'], true) ? $type : 'mixte',
            'telephone1' => C::telephone($e->tel_etab)[0],
            'telephone2' => C::telephone($e->tel1_etab)[0],
            'email' => C::texte($e->email_etab),
            'site_web' => C::texte($e->lien_etab),
            'boite_postale' => C::texte($e->bp ?? $info->bp ?? null),
            'quartier' => C::texte($e->quartier),
            'ville' => C::texte($e->ville),
            'region' => C::texte($e->region),
            'pays' => $pays && str_contains(C::cleNom($pays), 'IVOIRE') ? 'Côte d\'Ivoire' : ($pays ?? 'Côte d\'Ivoire'),
            'indicatif_telephonique' => $e->indicatif_telephonique ?: '225',
            'sms_actif' => (bool) ($info->is_used_sms ?? $e->is_used_sms),
            'sms_expediteur' => C::texte($e->sms_sender),
            'sms_credit' => (int) ($info->number_sms ?? $e->number_sms ?? 0),
            'slogan' => C::texte($e->slogan),
            'statut' => 'actif',
            // V1 etablissement.date_expiration = date d'echeance des recus / billets d'entree
            // (reglementController : date saisie a la caisse, sinon celle-ci), pas un abonnement.
            'date_expiration_abonnement' => null,
            'parametres' => json_encode([
                'effectif_max_classe' => (int) $e->nbre_el_clas ?: null,
                'formats_matricule' => ['99999999A', 'A99999999'],
                'numerotation_classes' => 'chiffres',
                // Billet d'entree en classe (Parametres paiements > Recu et billet) : date d'echeance
                // (V1 date_expiration) et nombre de jours si le paiement en est proche (V1 jour_expire_inscr).
                'date_validite_billet' => C::date($e->date_expiration) ? substr((string) C::date($e->date_expiration), 0, 10) : null,
                'delai_validite_recu' => (int) $e->jour_expire_inscr ?: null,
                'migration_v1' => ['date' => now()->toDateTimeString(), 'code_etab' => $e->code_etab],
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(EtablissementProvisioningService::class)->provisionner(Etablissement::withoutGlobalScopes()->findOrFail($this->etab));
        $this->lier('etablissement', $e->code_etab, 'etablissements', $this->etab);
    }

    private function anneesEtPeriodes(): void
    {
        $v1 = DB::connection('v1');
        $ans = collect()
            ->merge($v1->table('inscription')->whereNotNull('an_inscr')->distinct()->pluck('an_inscr'))
            ->merge($v1->table('classe')->where('an_classe', '>', 1900)->distinct()->pluck('an_classe'))
            ->merge($v1->table('cout_formation')->distinct()->pluck('an_cout_formation'))
            ->merge($v1->table('choix')->whereNotNull('annee')->distinct()->pluck('annee'))
            ->merge($v1->table('enseigner')->distinct()->pluck('an_ens'))
            ->merge($v1->table('dette')->whereNotNull('an_dette')->distinct()->pluck('an_dette')->map(fn ($a) => (int) $a - 1))
            ->map(fn ($a) => (int) $a)->filter(fn ($a) => $a > 1990)->unique()->sort()->values();
        $active = (int) $v1->table('inscription')->max('an_inscr');

        foreach (range($ans->first(), max($ans->last(), $active)) as $an) {
            $this->annees[$an] = DB::table('annees_scolaires')->insertGetId([
                'etablissement_id' => $this->etab, 'libelle' => C::anneeScolaire($an),
                'is_active' => $an === $active, 'is_cloturee' => $an < $active,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ([1 => '1er trimestre', 2 => '2eme trimestre', 3 => '3eme trimestre'] as $numero => $libelle) {
                DB::table('periodes')->insert([
                    'etablissement_id' => $this->etab, 'annee_scolaire_id' => $this->annees[$an],
                    'type_decoupage' => 'trimestre', 'numero' => $numero, 'libelle' => $libelle,
                    'is_active' => $an === $active && $numero === 1, 'is_cloturee' => $an < $active,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $this->compteurs['Années scolaires'] = count($this->annees);
    }

    private function referentiels(): void
    {
        $v1 = DB::connection('v1');
        $this->niveaux = DB::table('niveaux')->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();

        // Matieres : une par libelle V1 (doublons « EXPRESSION ORALE » fusionnes)
        $lignes = $v1->table('matiere')->get();
        foreach ($lignes->groupBy(fn ($m) => C::matiere($m->lib_mat)) as $lib => $groupe) {
            [$code, $libelle, $groupeBulletin] = C::MATIERES[$lib] ?? [substr(preg_replace('/[^A-Z]/', '', $lib), 0, 10), Str::title(strtolower($lib)), null];
            if (! isset(C::MATIERES[$lib])) {
                $this->anomalie('M1', 'Matière V1 sans correspondance prévue (créée telle quelle)', $lib);
            }
            $coefs = $groupe->pluck('coef_mat')->map(fn ($c) => (int) $c)->filter()->countBy()->sortDesc();
            $this->matieres[$code] = DB::table('matieres')->insertGetId([
                'etablissement_id' => $this->etab, 'code' => $code, 'libelle' => $libelle,
                'coefficient_defaut' => (int) ($coefs->keys()->first() ?? 1), 'groupe_bulletin' => $groupeBulletin,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($groupe as $m) {
                $this->matieresV1[$m->id_mat] = $this->matieres[$code];
            }
        }
        $this->compteurs['Matières'] = count($this->matieres);

        // Coefficients par niveau avec serie (E1, comme la V1 : MATH TLE A2 = 2, TLE D = 4)
        $niveauxV1 = $v1->table('niveau')->get()->keyBy('id_niveau');
        $n = 0;
        foreach ($lignes as $m) {
            $niv = $niveauxV1[$m->id_niveau] ?? null;
            $niveauId = $this->niveauId($niv?->lib_niveau);
            if (! $niveauId || (int) $m->coef_mat === 0) {
                continue;
            }
            $facultatives = array_map(fn ($x) => C::matiere(trim($x, "' ")), explode(',', (string) $niv->matiere_Nobligatoire));
            $n += DB::table('matiere_niveau')->insertOrIgnore([
                'matiere_id' => $this->matieresV1[$m->id_mat], 'niveau_id' => $niveauId,
                'coefficient' => (int) $m->coef_mat, 'is_obligatoire' => ! in_array(C::matiere($m->lib_mat), $facultatives, true),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->compteurs['Coefficients par niveau'] = $n;

        // Types de frais : ordre de paiement V1, actifs seulement s'ils servent
        $utilises = collect(['FRAIS_ANNEXES', 'INSCRIPTION', 'SCOLARITE']);
        foreach (C::COUTS_ANNEXES + ['cout_craie' => 'CRAIE', 'cout_rame' => 'RAME'] as $col => $code) {
            if ($v1->table('cout_formation')->where($col, '>', 0)->exists() || $v1->table('inscription')->where($col, '>', 0)->exists()) {
                $utilises->push($code);
            }
        }
        foreach ($v1->table('type_frais')->get() as $t) {
            if ($code = C::TYPES_FRAIS[$t->code_type_frais] ?? null) {
                DB::table('types_frais')->where('etablissement_id', $this->etab)->where('code', $code)
                    ->update(['ordre' => (int) $t->type_frais_ordre, 'is_active' => $utilises->contains($code), 'updated_at' => now()]);
            }
        }
        DB::table('types_frais')->where('etablissement_id', $this->etab)->whereNotIn('code', $utilises->all())->update(['is_active' => false]);
        $this->typesFrais = DB::table('types_frais')->where('etablissement_id', $this->etab)->get()
            ->mapWithKeys(fn ($t) => [$t->code => ['id' => (int) $t->id, 'ordre' => (int) $t->ordre, 'nature' => $t->nature]])->all();
        $this->typesReduction = DB::table('types_reductions')->where('etablissement_id', $this->etab)->pluck('id', 'code')->map(fn ($i) => (int) $i)->all();
    }

    private function personnel(): void
    {
        $v1 = DB::connection('v1');
        $admins = $v1->table('administration')->get()->keyBy('mat_admin');
        $derniere = $v1->table('reglement')->selectRaw('mat_admin, max(date_regle) d')->groupBy('mat_admin')->pluck('d', 'mat_admin');
        foreach ($admins as $a) {
            $this->rolesV1[$a->mat_admin] = $a->role_code;
        }

        // Groupes : fichier fourni, sinon meme nom + memes prenoms (a valider par l'ecole)
        $groupes = [];
        if ($fichier = $this->option('regroupements')) {
            foreach (file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ligne) {
                $mats = array_values(array_filter(array_map('trim', preg_split('/[;,]/', $ligne)), fn ($m) => isset($admins[$m])));
                if ($mats) {
                    $groupes[] = $mats;
                }
            }
            $places = array_merge(...$groupes ?: [[]]);
            foreach ($admins as $a) {
                if (! in_array($a->mat_admin, $places, true)) {
                    $groupes[] = [$a->mat_admin];
                }
            }
        } else {
            foreach ($admins->groupBy(fn ($a) => C::cleNom($a->nom_admin, $a->pnom_admin)) as $groupe) {
                $groupes[] = $groupe->pluck('mat_admin')->all();
            }
        }

        $roles = DB::table('roles')->where('etablissement_id', $this->etab)->pluck('id', 'code');
        $morph = (new User)->getMorphClass();
        foreach ($groupes as $mats) {
            $comptes = collect($mats)->map(fn ($m) => $admins[$m])
                ->reject(fn ($a) => in_array($a->role_code, C::POSTES_IGNORES, true));
            if ($comptes->isEmpty()) {
                foreach ($mats as $m) {
                    $this->anomalie('P1', 'Compte V1 de test non repris', "$m ({$admins[$m]->role_code})");
                }

                continue;
            }
            // Compte principal : actif, utilise le plus recemment
            $principal = $comptes->sortByDesc(fn ($a) => [(int) $a->actif, (string) ($derniere[$a->mat_admin] ?? $a->date_modif ?? '')])->first();
            $actifs = $comptes->filter(fn ($a) => (int) $a->actif === 1);
            [$tel, $etatTel] = C::telephone($principal->num_admin);
            if ($etatTel === 'anomalie') {
                $this->anomalie('T1', 'Téléphone du personnel non convertible (identifiant de connexion à corriger)', "{$principal->mat_admin} : {$principal->num_admin}");
            }
            if ($tel && DB::table('users')->where('telephone', $tel)->exists()) {
                $this->anomalie('T2', 'Téléphone du personnel déjà pris par un autre compte', "{$principal->mat_admin} : $tel");
            }
            $autres = $comptes->where('mat_admin', '!=', $principal->mat_admin);
            if ($autres->isNotEmpty()) {
                $this->anomalie('P2', 'Comptes V1 regroupés en un seul compte (à valider par l\'école)',
                    $comptes->map(fn ($a) => "{$a->mat_admin} {$a->role_code}".((int) $a->actif ? '' : ' (inactif)'))->join(' + ').' → principal '.$principal->mat_admin);
            }

            $motDePasse = Str::password(10, symbols: false);
            $userId = DB::table('users')->insertGetId([
                'etablissement_id' => $this->etab,
                'matricule' => $principal->mat_admin,
                'name' => C::majuscules($principal->nom_admin) ?? '?',
                'prenoms' => C::majuscules($principal->pnom_admin),
                'email' => null,
                'telephone' => $tel,
                'sexe' => in_array($principal->sexe_admin, ['M', 'F'], true) ? $principal->sexe_admin : null,
                'password' => Hash::make($motDePasse),
                'statut' => $actifs->isNotEmpty() ? 'actif' : 'inactif',
                'doit_changer_mot_de_passe' => true,
                'created_at' => C::dateHeure($comptes->min('date_creation')) ?? now(),
                'updated_at' => now(),
            ]);
            DB::table('etablissement_user')->insert(['etablissement_id' => $this->etab, 'user_id' => $userId, 'is_defaut' => true, 'created_at' => now(), 'updated_at' => now()]);

            $matiereProf = $comptes->pluck('matiere_prof')->filter()->map(fn ($l) => C::MATIERES[C::matiere($l)][0] ?? null)->filter()->unique();
            $statut = strtoupper((string) $comptes->pluck('statut')->filter()->first());
            $personnelId = DB::table('personnels')->insertGetId([
                'user_id' => $userId, 'etablissement_id' => $this->etab,
                'diplome' => ($d = C::texte($comptes->pluck('diplome')->filter()->first())) === 'XXX' ? null : $d,
                'statut_contrat' => match ($statut) { 'P', 'PERMANENT' => 'permanent', 'VACATAIRE' => 'vacataire', default => null },
                'date_embauche' => C::date($comptes->pluck('date_embauche')->filter()->min()),
                'matiere_principale_id' => $matiereProf->isNotEmpty() ? $this->matieres[$matiereProf->first()] ?? null : null,
                'nationalite' => C::nationalite($comptes->pluck('nationalite')->filter()->first()),
                'quartier' => ($q = C::texte($comptes->pluck('quartier')->filter()->first())) === 'XXX' ? null : $q,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($matiereProf as $code) {
                if (isset($this->matieres[$code])) {
                    DB::table('personnel_matieres')->insert(['personnel_id' => $personnelId, 'matiere_id' => $this->matieres[$code], 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            // Postes : ceux des comptes actifs (de tous si aucun n'est actif)
            $postes = [];
            foreach (($actifs->isNotEmpty() ? $actifs : $comptes) as $a) {
                $code = C::POSTES[$a->role_code] ?? null;
                if ($a->role_code === 'caisseFict') {
                    $this->anomalie('E6', 'Poste « Caisse fictive » pas encore en V2 (aucun poste donné)', $a->mat_admin);
                } elseif (! $code) {
                    $this->anomalie('P3', 'Poste V1 sans correspondance', "{$a->mat_admin} : {$a->role_code}");
                }
                if ($code && isset($roles[$code])) {
                    $postes[$code] = (int) $roles[$code];
                }
            }
            foreach ($postes as $roleId) {
                DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => $morph, 'model_id' => $userId, 'etablissement_id' => $this->etab]);
            }

            foreach ($comptes as $a) {
                $this->users[$a->mat_admin] = $userId;
                $this->personnels[$a->mat_admin] = $personnelId;
                $this->usersParNom[C::cleNom($a->nom_admin, $a->pnom_admin)] = $userId;
                $this->lier('administration', $a->mat_admin, 'users', $userId, ['role_v1' => $a->role_code, 'principal' => $a->mat_admin === $principal->mat_admin]);
            }
            $this->motsDePasse[] = [$tel ?? '', C::majuscules($principal->nom_admin), C::majuscules($principal->pnom_admin), implode(', ', array_keys($postes)), $actifs->isNotEmpty() ? 'actif' : 'inactif', $motDePasse];
        }
        $this->compteurs['Comptes du personnel'] = count(array_unique($this->users));
        $this->compteurs['Comptes V1 regroupés'] = count($this->users) - count(array_unique($this->users));

        // Niveaux des educateurs (niveau general)
        $vus = [];
        foreach ($v1->table('choix')->get() as $c) {
            [$general] = C::niveau($c->niveau_gen);
            if (! $c->annee || ! isset($this->annees[(int) $c->annee])) {
                $this->anomalie('P4', 'Niveau d\'éducateur sans année (ignoré)', "{$c->mat_admin} {$c->niveau_gen}");

                continue;
            }
            if (! isset($this->personnels[$c->mat_admin]) || ! $general || ! isset($this->niveaux[$general])) {
                continue;
            }
            $cle = "{$c->annee}|{$this->personnels[$c->mat_admin]}|$general";
            if (isset($vus[$cle])) {
                continue;
            }
            $vus[$cle] = true;
            DB::table('educateur_niveaux')->insert([
                'etablissement_id' => $this->etab, 'annee_scolaire_id' => $this->annees[(int) $c->annee],
                'personnel_id' => $this->personnels[$c->mat_admin], 'niveau_id' => $this->niveaux[$general],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->compteurs['Niveaux des éducateurs'] = count($vus);
    }

    /**
     * Recopie le compte qui lance la reprise (meme e-mail, meme mot de passe)
     * comme Super admin de l'ecole reprise : les comptes V1 n'ont pas d'e-mail
     * et la V2 se connecte par e-mail.
     */
    private function compteAdministrateur(): void
    {
        $c = $this->compte;
        $userId = (int) DB::table('users')->where('email', $c->email)->value('id');
        if (! $userId) {
            $userId = DB::table('users')->insertGetId([
                'etablissement_id' => $this->etab, 'matricule' => null, 'name' => $c->name, 'prenoms' => $c->prenoms,
                'email' => $c->email, 'telephone' => $c->telephone, 'sexe' => $c->sexe, 'password' => $c->password,
                'statut' => 'actif', 'doit_changer_mot_de_passe' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('etablissement_user')->insertOrIgnore(['etablissement_id' => $this->etab, 'user_id' => $userId, 'is_defaut' => true, 'created_at' => now(), 'updated_at' => now()]);
        $role = DB::table('roles')->where('etablissement_id', $this->etab)->where('code', 'super_admin')->value('id');
        DB::table('model_has_roles')->insertOrIgnore(['role_id' => $role, 'model_type' => (new User)->getMorphClass(), 'model_id' => $userId, 'etablissement_id' => $this->etab]);
        $this->compteurs['Compte administrateur recopié'] = $c->email;
    }

    private function userTechnique(): int
    {
        return $this->userReprise ??= DB::table('users')->insertGetId([
            'etablissement_id' => $this->etab, 'matricule' => 'REPRISE-V1', 'name' => 'REPRISE V1', 'prenoms' => 'CAISSIER INCONNU',
            'password' => Hash::make(Str::random(40)), 'statut' => 'inactif', 'doit_changer_mot_de_passe' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function classesEtAffectations(): void
    {
        $v1 = DB::connection('v1');
        $pp = $v1->table('enseigner')->where('pp', 1)->orderBy('id_ens')->pluck('mat_admin', 'code_classe');
        foreach ($v1->table('classe')->orderBy('code_classe')->get() as $c) {
            [$general, $serie] = C::niveau($c->lib_niveau);
            if (! $general || ! isset($this->annees[(int) $c->an_classe]) || ! isset($this->niveaux[$general])) {
                $this->anomalie('C1', 'Classe V1 vide ou sans niveau (ignorée)', "code_classe {$c->code_classe}");

                continue;
            }
            // Nom V2 : libelle du niveau + numero en exposant (« TleA2³ »).
            $niveauId = $this->niveauId($c->lib_niveau);
            $libelle = NomClasse::composer((string) DB::table('niveaux')->where('id', $niveauId)->value('libelle'), (string) $c->lib_classe);
            $this->classes[$c->code_classe] = DB::table('classes')->insertGetId([
                'etablissement_id' => $this->etab, 'annee_scolaire_id' => $this->annees[(int) $c->an_classe],
                'niveau_id' => $niveauId, 'libelle' => $libelle,
                'salle' => (int) $c->num_salle > 0 ? (string) $c->num_salle : null,
                'capacite' => (int) ($c->limite_effclas ?? 0) ?: null,
                'langue_vivante_2' => C::langue($c->lv2),
                'professeur_principal_id' => $this->personnels[$c->mat_adminPP ?? ''] ?? $this->personnels[$pp[$c->code_classe] ?? ''] ?? null,
                'educateur_id' => $this->personnels[$c->mat_adminEducateur ?? ''] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->lier('classe', $c->code_classe, 'classes', $this->classes[$c->code_classe], ['niveau_v1' => $c->lib_niveau]);
        }
        $this->compteurs['Classes'] = count($this->classes);

        $affectations = [];
        foreach ($v1->table('enseigner')->orderBy('id_ens')->get() as $e) {
            $classe = $this->classes[$e->code_classe] ?? null;
            $matiere = $this->matieresV1[$e->id_mat] ?? null;
            $personnel = $this->personnels[$e->mat_admin] ?? null;
            if (! $classe || ! $matiere || ! $personnel || ! isset($this->annees[(int) $e->an_ens])) {
                $this->anomalie('A14', 'Affectation d\'enseignant incomplète (ignorée)', "id_ens {$e->id_ens} ({$e->mat_admin})");

                continue;
            }
            // La plus recente gagne (id_ens le plus grand)
            $affectations["$classe|$matiere|{$e->an_ens}"] = [
                'annee_scolaire_id' => $this->annees[(int) $e->an_ens], 'personnel_id' => $personnel,
                'classe_id' => $classe, 'matiere_id' => $matiere, 'volume_horaire_hebdo' => null,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        $this->insererLot('affectations_enseignants', array_values($affectations));
        $this->compteurs['Affectations d\'enseignants'] = count($affectations);
    }

    private function grilles(): void
    {
        $n = 0;
        foreach (DB::connection('v1')->table('cout_formation')->orderBy('id_cout_formation')->get()->groupBy(fn ($g) => "{$g->an_cout_formation}|{$g->lib_niveau}|".(int) $g->affecte) as $groupe) {
            $g = $groupe->last();
            [$general] = C::niveau($g->lib_niveau);
            if (! $general || ! isset($this->annees[(int) $g->an_cout_formation])) {
                $this->anomalie('G1', 'Grille V1 sans niveau ou sans année (ignorée)', "id {$g->id_cout_formation}");

                continue;
            }
            $id = DB::table('grilles_tarifaires')->insertGetId([
                'etablissement_id' => $this->etab, 'annee_scolaire_id' => $this->annees[(int) $g->an_cout_formation],
                'niveau_id' => $this->niveaux[$general], 'affecte' => (bool) $g->affecte,
                // E5 : minimum V1 repris tel quel (saisi par l'ecole)
                'montant_minimum_inscription' => C::montant($g->montant_mini),
                'created_at' => C::dateHeure($g->date_create) ?? now(), 'updated_at' => C::dateHeure($g->date_modif) ?? now(),
            ]);
            $montants = ['INSCRIPTION' => $g->frais_inscr, 'SCOLARITE' => $g->scolarite, 'FRAIS_ANNEXES' => $g->frais_anexe];
            foreach (C::COUTS_ANNEXES + ['cout_craie' => 'CRAIE', 'cout_rame' => 'RAME'] as $col => $code) {
                $montants[$code] = $g->{$col};
            }
            foreach ($montants as $code => $montant) {
                if (C::montant($montant) > 0 || in_array($code, ['INSCRIPTION', 'SCOLARITE', 'FRAIS_ANNEXES'], true)) {
                    DB::table('grille_tarifaire_lignes')->insert(['grille_tarifaire_id' => $id, 'type_frais_id' => $this->typesFrais[$code]['id'], 'montant' => C::montant($montant), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $n++;
        }
        $this->compteurs['Grilles tarifaires'] = $n;
    }

    private function elevesEtTuteurs(): void
    {
        $v1 = DB::connection('v1');
        $derniere = $v1->table('inscription')->orderBy('an_inscr')->orderBy('id_inscr')->get()->keyBy(fn ($i) => trim($i->matricule));
        $eleves = $v1->table('eleve')->get();
        $lignes = [];
        $matricules = [];
        foreach ($eleves as $e) {
            $mat = strtoupper(trim($e->matricule));
            if (isset($matricules[$mat])) {
                $this->anomalie('E-DBL', 'Matricule en double après nettoyage (ignoré)', $mat);

                continue;
            }
            $matricules[$mat] = $e;
            $i = $derniere[$mat] ?? $derniere[$e->matricule] ?? null;
            if (! C::date($e->date_nais) || $e->date_nais < '1980' || $e->date_nais > '2022') {
                $this->anomalie('A18', 'Date de naissance vide ou improbable', "$mat : ".($e->date_nais ?? 'vide'));
            }
            [$tel] = C::telephone($e->num_eleve);
            $lignes[] = [
                'etablissement_id' => $this->etab, 'matricule' => $mat,
                'nom' => C::majuscules($e->nom) ?? '?', 'prenoms' => C::majuscules($e->pnom) ?? '',
                'date_naissance' => C::date($e->date_nais), 'lieu_naissance' => C::majuscules($e->lieu_nais),
                'sexe' => in_array($e->sexe, ['M', 'F'], true) ? $e->sexe : null, 'telephone' => $tel,
                'nationalite' => C::nationalite($e->nationnalite), 'photo_path' => null,
                'particularites_medicales' => C::texte($i?->handicap),
                'orphelin_pere' => (bool) ($i?->orphelin_pere), 'orphelin_mere' => (bool) ($i?->orphelin_mere),
                'quartier' => C::majuscules($i?->quartier),
                'statut' => (int) $e->actif === 1 && $i ? 'actif' : 'inactif',
                'created_at' => C::dateHeure($i?->date_create) ?? now(), 'updated_at' => now(),
            ];
        }
        $ids = $this->insererLot('eleves', $lignes);
        foreach ($lignes as $k => $l) {
            $this->eleves[$l['matricule']] = $ids[$k];
            $this->lier('eleve', $l['matricule'], 'eleves', $ids[$k]);
        }
        $this->compteurs['Élèves'] = count($ids);

        // Tuteurs : pere, mere, tuteur de la derniere inscription ; un meme numero + un meme nom = une seule fiche
        $tuteurs = [];   // cle => [ligne, index]
        $liens = [];
        $etats = [];
        foreach ($matricules as $mat => $e) {
            $i = $derniere[$mat] ?? $derniere[$e->matricule] ?? null;
            $sources = [['pere', $e->pere, $e->num_pere], ['mere', $e->mere, $e->num_mere], ['tuteur_legal', $i?->tuteur, $i?->num_tuteur]];
            $principal = true;
            $dejaLies = [];
            foreach ($sources as [$lien, $nom, $numero]) {
                [$tel, $etat] = C::telephone($numero);
                $etats[$etat] = ($etats[$etat] ?? 0) + 1;
                $nom = C::majuscules($nom);
                if (! $nom && ! $tel) {
                    continue;
                }
                if ($etat === 'anomalie') {
                    $this->anomalie('A23', 'Téléphone de parent non convertible (gardé tel quel)', "$mat ($lien) : $numero");
                }
                $cle = $tel ? $tel.'|'.C::cleNom($nom) : 'sans-numero|'.$mat.'|'.$lien;
                if (! isset($tuteurs[$cle])) {
                    $mots = explode(' ', (string) $nom, 2);
                    $tuteurs[$cle] = [
                        'nom' => $mots[0] ?: 'NON RENSEIGNÉ', 'prenoms' => $mots[1] ?? '',
                        'telephone1' => $tel, 'created_at' => now(), 'updated_at' => now(),
                    ];
                }
                if (isset($dejaLies[$cle])) {
                    continue;
                }
                $dejaLies[$cle] = true;
                $liens[] = ['cle' => $cle, 'eleve_id' => $this->eleves[$mat], 'lien_parente' => $lien,
                    'is_contact_principal' => $principal && $tel !== null, 'is_payeur' => $principal && $tel !== null];
                if ($tel) {
                    $principal = false;
                }
            }
        }
        $cles = array_keys($tuteurs);
        $ids = $this->insererLot('tuteurs', array_values($tuteurs));
        $idParCle = array_combine($cles, $ids);
        $this->insererLot('eleve_tuteur', array_map(fn ($l) => [
            'eleve_id' => $l['eleve_id'], 'tuteur_id' => $idParCle[$l['cle']], 'lien_parente' => $l['lien_parente'],
            'is_contact_principal' => $l['is_contact_principal'], 'is_payeur' => $l['is_payeur'],
            'created_at' => now(), 'updated_at' => now(),
        ], $liens));
        $this->compteurs['Tuteurs'] = count($ids);
        $this->compteurs['Liens élève-tuteur'] = count($liens);
        foreach ($etats as $etat => $n) {
            $this->compteurs["Téléphones des parents : $etat"] = $n;
        }
    }

    private function inscriptionsV1(): void
    {
        $v1 = DB::connection('v1');
        $avecReglement = $v1->table('reglement')->distinct()->pluck('id_inscr')->flip();
        $enLigne = Schema::connection('v1')->hasTable('en_ligne') ? $v1->table('en_ligne')->get()->keyBy(fn ($l) => trim($l->matricule).'|'.$l->aninscr_ligne) : collect();

        $groupes = [];
        foreach ($v1->table('inscription')->orderBy('id_inscr')->get() as $i) {
            $mat = strtoupper(trim($i->matricule));
            $an = (int) $i->an_inscr;
            if (! $an && ($d = C::date($i->date_inscr))) {
                $an = (int) substr($d, 5, 2) >= 8 ? (int) substr($d, 0, 4) : (int) substr($d, 0, 4) - 1;
                $this->anomalie('A3', 'Inscription sans année (déduite de la date)', "id_inscr {$i->id_inscr} → $an");
            }
            if (! isset($this->eleves[$mat])) {
                $this->anomalie('A2', 'Inscription d\'un matricule inconnu (rejetée)', "id_inscr {$i->id_inscr} : $mat");

                continue;
            }
            if (! isset($this->annees[$an])) {
                $this->anomalie('A3', 'Inscription sans année exploitable (rejetée)', "id_inscr {$i->id_inscr}");

                continue;
            }
            $groupes["$mat|$an"][] = $i;
        }

        $lignes = [];
        $sources = [];
        foreach ($groupes as $cle => $groupe) {
            [$mat, $an] = explode('|', $cle);
            $an = (int) $an;
            $garde = collect($groupe)->sortByDesc(fn ($i) => [isset($avecReglement[$i->id_inscr]) ? 1 : 0, (int) $i->id_inscr])->first();
            if (count($groupe) > 1) {
                $this->anomalie('A4', 'Doublon d\'inscription élève / année (fusionnée)', "$mat $an : ".collect($groupe)->pluck('id_inscr')->join(', ')." → {$garde->id_inscr}");
            }
            [$general, $serie] = C::niveau($garde->niveau_act);
            if ($garde->decision_finale && ! C::decision($garde->decision_finale)) {
                $this->anomalie('I1', 'Décision de fin d\'année inconnue (vidée)', "id_inscr {$garde->id_inscr} : {$garde->decision_finale}");
            }
            $ligneEnLigne = $enLigne["$mat|$an"] ?? null;
            $lignes[] = [
                'etablissement_id' => $this->etab, 'annee_scolaire_id' => $this->annees[$an], 'eleve_id' => $this->eleves[$mat],
                'classe_id' => $this->classes[$garde->code_classe ?? ''] ?? null,
                'niveau_id' => $this->niveauId($garde->niveau_act),
                'date_inscription' => C::dateHeure($garde->date_inscr) ?? C::dateHeure($garde->date_create) ?? "$an-10-01 00:00:00",
                'statut' => max(0, min(3, (int) $garde->inscr_termine)),
                'redoublant' => (bool) $garde->redoublant, 'affecte' => (bool) $garde->affecte, 'boursier' => (bool) $garde->boursier,
                'langue_vivante_2' => C::langue($garde->langue),
                'etablissement_origine' => C::texte($garde->etab_origine), 'classe_origine' => C::texte($garde->classe_origine),
                'decision_origine' => null, 'moyenne_origine' => null,
                'decision_finale' => C::decision($garde->decision_finale), 'moyenne_annuelle' => null,
                'niveau_a_suivre_id' => $this->niveauId($garde->niveau_a_suivre),
                'inscrit_en_ligne' => (bool) ($garde->inscr_ligne ?? false),
                'inscription_en_ligne' => $ligneEnLigne ? json_encode(['source' => 'V1 en_ligne'] + (array) $ligneEnLigne) : null,
                'montant_total_du' => 0, 'montant_total_reduit' => 0, 'montant_total_paye' => 0,
                'enregistre_par_id' => $this->usersParNom[C::cleNom($garde->nom_educateur)] ?? null,
                'created_at' => C::dateHeure($garde->date_create) ?? C::dateHeure($garde->date_inscr) ?? now(),
                'updated_at' => C::dateHeure($garde->date_modif) ?? now(),
            ];
            $sources[] = [$groupe, $an, $mat, $garde->niveau_act, $serie];
        }
        $ids = $this->insererLot('inscriptions', $lignes);
        foreach ($sources as $k => [$groupe, $an, $mat, $niveauV1, $serie]) {
            foreach ($groupe as $i) {
                $this->inscriptions[$i->id_inscr] = ['id' => $ids[$k], 'an' => $an, 'eleve_id' => $this->eleves[$mat], 'v1' => $i];
                $this->lier('inscription', $i->id_inscr, 'inscriptions', $ids[$k], array_filter(['niveau_v1' => $niveauV1, 'serie' => $serie]));
            }
        }
        $this->compteurs['Inscriptions'] = count($ids);
    }

    private function dettesV1(): void
    {
        $v1 = DB::connection('v1');
        $lignes = [];
        $sources = [];
        foreach ($v1->table('dette')->whereNotNull('matricule')->where('matricule', '!=', '')->orderBy('id_dette')->get() as $d) {
            $mat = strtoupper(trim($d->matricule));
            $origine = (int) $d->an_dette - 1;
            if (! isset($this->eleves[$mat]) || ! isset($this->annees[$origine])) {
                $this->anomalie('D1', 'Dette d\'un élève ou d\'une année inconnus (rejetée)', "id_dette {$d->id_dette}");

                continue;
            }
            $cle = $this->eleves[$mat].'|'.$origine;
            if (isset($this->dettesParEleveAnnee[$cle])) {
                $this->anomalie('D2', 'Deux dettes pour le même élève et la même année (la 2e est rejetée)', "id_dette {$d->id_dette}");

                continue;
            }
            $this->dettesParEleveAnnee[$cle] = true;
            $lignes[] = [
                'etablissement_id' => $this->etab, 'eleve_id' => $this->eleves[$mat], 'annee_scolaire_id' => $this->annees[$origine],
                'montant' => C::montant($d->mont_dette), 'montant_reduit' => 0, 'montant_paye' => 0,
                'is_annulee' => (bool) $d->dette_annule, 'enregistre_par_id' => $this->users[$d->mat_admin ?? ''] ?? null,
                'created_at' => C::dateHeure($d->date_create) ?? now(), 'updated_at' => C::dateHeure($d->date_modif) ?? now(),
            ];
            $sources[] = [$d, $cle];
        }
        $ids = $this->insererLot('dettes', $lignes);
        foreach ($sources as $k => [$d, $cle]) {
            $this->dettes[$d->id_dette] = $ids[$k];
            $this->dettesParEleveAnnee[$cle] = $ids[$k];
            $this->lier('dette', $d->id_dette, 'dettes', $ids[$k]);
        }
        $this->compteurs['Dettes'] = count($ids);
        $this->compteurs['Lignes « dette » V1 vides ignorées'] = $v1->table('dette')->where(fn ($q) => $q->whereNull('matricule')->orWhere('matricule', ''))->count();
    }

    /** Montants dus (bruts) et reductions, pour les inscriptions dont les finances sont reprises. */
    private function fraisEtReductions(): void
    {
        $v1 = DB::connection('v1');
        $grilles = $v1->table('cout_formation')->orderBy('id_cout_formation')->get()
            ->keyBy(fn ($g) => $g->an_cout_formation.'|'.C::niveau($g->lib_niveau)[0].'|'.(int) $g->affecte);
        $reductionsV1 = $v1->table('reduction')->orderBy('id_reduction')->get()->groupBy('id_inscr');
        $payeParType = $this->paiementsParType();

        $lignes = [];
        $reductions = [];   // [cle frais, montant, motif, actif, lot, type, date]
        $ecarts = 0;
        foreach ($this->inscriptions as $idV1 => $ins) {
            $i = $ins['v1'];
            if ($ins['an'] < $this->financesDepuis || isset($this->frais[$ins['id']]) || (int) $idV1 !== (int) $this->idGarde($ins['id'])) {
                continue;
            }
            $aff = (bool) $i->affecte;
            $red = C::montant($i->reduction);
            $cas = (int) $i->is_cas === 2;
            $paye = $payeParType[$ins['id']] ?? [];
            [$general] = C::niveau($i->niveau_act);
            $grille = $grilles[$ins['an'].'|'.$general.'|'.(int) $aff] ?? null;

            $du = ['FRAIS_ANNEXES' => C::montant($i->montant_fr_anex), 'INSCRIPTION' => C::montant($i->montant_fr_inscr), 'SCOLARITE' => C::montant($i->montant_fr_scolar)];
            if ($cas && $red > 0) {
                if ($grille) {
                    $du = ['FRAIS_ANNEXES' => C::montant($grille->frais_anexe), 'INSCRIPTION' => C::montant($grille->frais_inscr), 'SCOLARITE' => $aff ? 0 : C::montant($grille->scolarite)];
                } else {
                    $du['FRAIS_ANNEXES'] += $red;
                    $this->anomalie('R1', 'Cas social sans grille : réduction reportée sur les annexes', "id_inscr $idV1");
                }
            } elseif ($red > 0) {
                $du[$aff ? 'INSCRIPTION' : 'SCOLARITE'] += $red;
            }
            foreach (C::COUTS_ANNEXES as $col => $code) {
                $du[$code] = C::montant($i->{$col});
            }
            foreach (['FRAIS_ANNEXES', 'INSCRIPTION', 'SCOLARITE'] as $code) {
                if ($du[$code] <= 0 && empty($paye[$code])) {
                    unset($du[$code]);
                }
            }
            foreach ($du as $code => $montant) {
                if ($montant <= 0 && ! in_array($code, ['FRAIS_ANNEXES', 'INSCRIPTION', 'SCOLARITE'], true)) {
                    continue;
                }
                $lignes[] = ['inscription_id' => $ins['id'], 'type_frais_id' => $this->typesFrais[$code]['id'], 'montant_du' => $montant,
                    'montant_reduit' => 0, 'montant_paye' => 0, 'quantite_due' => null, 'quantite_remise' => 0,
                    'created_at' => now(), 'updated_at' => now()];
                $this->frais[$ins['id']][$code] = true;
            }
            foreach (['cout_craie' => 'CRAIE', 'cout_rame' => 'RAME'] as $col => $code) {
                if (($q = (int) $i->{$col}) > 0) {
                    $lignes[] = ['inscription_id' => $ins['id'], 'type_frais_id' => $this->typesFrais[$code]['id'], 'montant_du' => 0,
                        'montant_reduit' => 0, 'montant_paye' => 0, 'quantite_due' => $q, 'quantite_remise' => max(0, $q - (int) $i->{'reste_'.$col}),
                        'created_at' => now(), 'updated_at' => now()];
                    $this->frais[$ins['id']][$code] = true;
                }
            }
            // V1 : montant_reduit = frais - reduction + dette - reduction de la dette ; vide pour un paiement de dette seule
            if ($i->montant_reduit !== null && array_sum(array_intersect_key($du, array_flip(['FRAIS_ANNEXES', 'INSCRIPTION', 'SCOLARITE']))) - $red
                !== C::montant($i->montant_reduit) - C::montant($i->dette) + C::montant($i->reduction_dette)) {
                $ecarts++;
                $this->anomalie('F1', 'Montant dû reconstitué ≠ montant réduit V1 − dette V1', "id_inscr $idV1");
            }

            // Reductions : les lignes V1 si elles totalisent inscription.reduction, sinon une reduction « Reprise V1 »
            if ($red > 0 || isset($reductionsV1[$idV1])) {
                $type = $this->typesReduction[$cas ? 'CAS' : 'REDUCTION'] ?? null;
                $cibles = $cas ? ['INSCRIPTION', 'SCOLARITE', 'FRAIS_ANNEXES'] : [$aff ? 'INSCRIPTION' : 'SCOLARITE'];
                $capacite = array_map(fn ($c) => $du[$c] ?? 0, array_combine($cibles, $cibles));
                $v1Lignes = collect($reductionsV1[$idV1] ?? []);
                $actives = $v1Lignes->where('is_actif', 1);
                if ($red > 0 && C::montant($actives->sum('montant_reduction')) !== $red) {
                    if ($actives->isNotEmpty()) {
                        $this->anomalie('A11', 'Somme des réductions V1 ≠ inscription.reduction (inscription.reduction fait foi)', "id_inscr $idV1");
                    }
                    $v1Lignes = collect([(object) ['montant_reduction' => $red, 'entite_reduc' => $v1Lignes->pluck('entite_reduc')->filter()->unique()->join(', ') ?: 'Reprise V1', 'is_actif' => 1, 'date_reduction' => $i->date_inscr]]);
                }
                foreach ($v1Lignes as $r) {
                    $reste = C::montant($r->montant_reduction);
                    $lot = (string) Str::uuid();
                    foreach ($cibles as $c) {
                        if ($reste <= 0) {
                            break;
                        }
                        $part = (int) $r->is_actif === 1 ? min($reste, $capacite[$c]) : $reste;
                        if ($part <= 0) {
                            continue;
                        }
                        if ((int) $r->is_actif === 1) {
                            $capacite[$c] -= $part;
                        }
                        $reste -= $part;
                        $reductions[] = [$ins['id'], $c, $part, C::texte($r->entite_reduc), (int) $r->is_actif === 1, $lot, $type, C::dateHeure($r->date_reduction)];
                    }
                    if ($reste > 0) {
                        $this->anomalie('R2', 'Réduction supérieure aux frais visés (reste non imputé)', "id_inscr $idV1 : $reste");
                    }
                }
            }
        }
        $ids = $this->insererLot('frais_eleves', $lignes);
        foreach ($lignes as $k => $l) {
            $this->frais[$l['inscription_id']][$this->codeType($l['type_frais_id'])] = $ids[$k];
        }
        $this->compteurs['Frais dus des élèves'] = count($ids);
        $this->compteurs['Inscriptions dont le dû diffère de la V1'] = $ecarts;

        $this->insererLot('reductions', array_values(array_filter(array_map(fn ($r) => isset($this->frais[$r[0]][$r[1]]) ? [
            'etablissement_id' => $this->etab, 'lot' => $r[5], 'type_reduction_id' => $r[6], 'frais_eleve_id' => $this->frais[$r[0]][$r[1]], 'dette_id' => null,
            'montant' => $r[2], 'motif' => $r[3] ?? 'Reprise V1', 'accorde_par_id' => null, 'is_active' => $r[4],
            'created_at' => $r[7] ?? now(), 'updated_at' => now(),
        ] : null, $reductions))));
        $this->compteurs['Réductions sur frais'] = count($reductions);

        // Reductions sur dettes (dette.reduction ; annulee = tout le reste au moment de l'annulation)
        $payeDette = $this->paiementsDettes();
        $lignes = [];
        foreach ($v1->table('dette')->whereNotNull('matricule')->where('matricule', '!=', '')->get() as $d) {
            if (! isset($this->dettes[$d->id_dette])) {
                continue;
            }
            $montant = (int) $d->dette_annule === 1
                ? C::montant($d->mont_dette) - ($payeDette[$d->id_dette] ?? 0)
                : C::montant($d->reduction);
            if ($montant > 0) {
                $lignes[] = ['etablissement_id' => $this->etab, 'lot' => null, 'type_reduction_id' => $this->typesReduction['REDUCTION'] ?? null,
                    'frais_eleve_id' => null, 'dette_id' => $this->dettes[$d->id_dette], 'montant' => $montant,
                    'motif' => (int) $d->dette_annule === 1 ? 'Annulation de la dette (V1)' : 'Réduction sur dette (V1)',
                    'accorde_par_id' => $this->users[$d->mat_admin ?? ''] ?? null, 'is_active' => true,
                    'created_at' => C::dateHeure($d->date_modif) ?? now(), 'updated_at' => now()];
            }
        }
        $this->insererLot('reductions', $lignes);
        $this->compteurs['Réductions sur dettes'] = count($lignes);
    }

    private function reglements(): void
    {
        $v1 = DB::connection('v1');
        $fraisV1 = $v1->table('frais')->get()->groupBy('code_regle');
        $reglementsV1 = $v1->table('reglement')->get()
            ->filter(fn ($r) => isset($this->inscriptions[$r->id_inscr]) && $this->inscriptions[$r->id_inscr]['an'] >= $this->financesDepuis)
            ->sortBy(fn ($r) => [C::dateHeure($r->date_regle) ?? '9999', (int) $r->code_regle]);
        $this->compteurs['Règlements V1 antérieurs à '.$this->financesDepuis.' (non repris)'] = $v1->table('reglement')->count() - $reglementsV1->count();

        $sequences = [];
        $lignesReglements = [];
        $lignesParReglement = [];
        $sansDetail = [];
        $fictifs = [];
        foreach ($reglementsV1 as $r) {
            $ins = $this->inscriptions[$r->id_inscr];
            $an = $ins['an'];
            $detail = [];
            foreach ($fraisV1[$r->code_regle] ?? [] as $f) {
                $montant = C::montant($f->montant_frais);
                $type = strtoupper((string) $f->type_frais);
                if ($montant === 0 || in_array($type, ['CRAIE', 'RAME'], true)) {
                    continue;
                }
                if ($montant < 0) {
                    $this->anomalie('A9', 'Ligne de règlement négative (ignorée, à revoir)', "code_regle {$r->code_regle} : $montant");

                    continue;
                }
                if ($type === 'DETTE') {
                    $dette = $this->dettes[$r->id_dette ?? ''] ?? $this->dettesParEleveAnnee[$ins['eleve_id'].'|'.($an - 1)] ?? null;
                    if (! is_int($dette)) {
                        $this->anomalie('D3', 'Paiement de dette sans dette retrouvée (ignoré)', "code_regle {$r->code_regle} : $montant");

                        continue;
                    }
                    $detail[] = ['frais_eleve_id' => null, 'dette_id' => $dette, 'montant' => $montant];

                    continue;
                }
                $code = C::TYPES_FRAIS[$type] ?? null;
                $frais = $code ? ($this->frais[$ins['id']][$code] ?? null) : null;
                if (! is_int($frais)) {
                    $this->anomalie('F2', 'Paiement sur un frais absent de l\'inscription (ignoré)', "code_regle {$r->code_regle} : $type $montant");

                    continue;
                }
                $detail[] = ['frais_eleve_id' => $frais, 'dette_id' => null, 'montant' => $montant];
            }
            if (! $detail) {
                if (C::montant($r->montant_regle) <= 0) {
                    $this->anomalie('A5b', 'Règlement V1 sans montant (ignoré)', "code_regle {$r->code_regle}");

                    continue;
                }
                $sansDetail[] = $r;
            }
            $attendu = C::montant($r->montant_regle) + collect($detail)->whereNotNull('dette_id')->sum('montant');
            if ($detail && collect($detail)->sum('montant') !== $attendu) {
                $this->anomalie('A8', 'Montant du règlement ≠ somme de ses lignes (les lignes font foi)', "code_regle {$r->code_regle}");
            }
            $date = C::dateHeure($r->date_regle);
            if (! $date || $date < ($an - 1).'-01-01' || $date > ($an + 2).'-12-31') {
                $this->anomalie('A5', 'Date de règlement vide ou aberrante (date de l\'inscription)', "code_regle {$r->code_regle} : ".($r->date_regle ?? 'vide'));
                $date = $ins['v1']->date_inscr ? C::dateHeure($ins['v1']->date_inscr) : "$an-10-01 00:00:00";
            }
            $caissier = $this->users[$r->mat_admin ?? ''] ?? null;
            if (! $caissier) {
                $this->anomalie('A6', 'Règlement sans caissier connu (compte « Reprise V1 »)', "code_regle {$r->code_regle}");
                $caissier = $this->userTechnique();
            }
            $prefixe = C::prefixeNumero($an);
            $sequences[$prefixe] = ($sequences[$prefixe] ?? 0) + 1;
            $fictif = ($this->rolesV1[$r->mat_admin ?? ''] ?? null) === 'caisseFict';
            $lignesReglements[] = [
                'etablissement_id' => $this->etab, 'eleve_id' => $ins['eleve_id'], 'annee_scolaire_id' => $this->annees[$an],
                'inscription_id' => $ins['id'], 'caissier_id' => $caissier,
                'numero_recu' => $prefixe.'-'.str_pad((string) $sequences[$prefixe], 5, '0', STR_PAD_LEFT),
                'numero_versement' => (int) $r->num_reglement > 0 ? (int) $r->num_reglement : null,
                'montant_total' => collect($detail)->sum('montant') ?: C::montant($r->montant_regle),
                'mode_paiement' => 'especes', 'date_paiement' => $date, 'date_expiration' => C::date($r->date_expiration),
                'created_at' => $date, 'updated_at' => $date,
            ];
            $lignesParReglement[] = [$r, $detail, $fictif];
            if ((int) $r->num_reglement > 6) {
                $this->anomalie('A24', 'Numéro de versement supérieur à 6 (repris tel quel)', "code_regle {$r->code_regle} : n° {$r->num_reglement}");
            }
            if ($fictif) {
                $fictifs[$an] = ($fictifs[$an] ?? 0) + (int) $lignesReglements[array_key_last($lignesReglements)]['montant_total'];
            }
        }
        $ids = $this->insererLot('reglements', $lignesReglements);

        // Lignes ; reglements sans detail repartis ensuite selon l'ordre des frais (regle V1)
        $lignes = [];
        $paye = [];
        foreach ($lignesParReglement as $k => [$r, $detail, $fictif]) {
            foreach ($detail as $d) {
                $lignes[] = ['reglement_id' => $ids[$k], 'created_at' => $lignesReglements[$k]['date_paiement'], 'updated_at' => now()] + $d;
                if ($d['frais_eleve_id']) {
                    $paye[$d['frais_eleve_id']] = ($paye[$d['frais_eleve_id']] ?? 0) + $d['montant'];
                }
            }
            $this->lier('reglement', $r->code_regle, 'reglements', $ids[$k], array_filter([
                'ancien_numero_recu' => (string) $r->code_regle, 'numero_versement' => (int) $r->num_reglement, 'caisse_fictive' => $fictif ?: null,
            ], fn ($v) => $v !== null));
        }
        $index = array_flip(array_map(fn ($x) => (int) $x[0]->code_regle, $lignesParReglement));
        foreach ($sansDetail as $r) {
            $k = $index[(int) $r->code_regle];
            $ins = $this->inscriptions[$r->id_inscr];
            $reste = C::montant($r->montant_regle);
            $frais = collect($this->frais[$ins['id']] ?? [])->filter(fn ($id) => is_int($id))
                ->sortBy(fn ($id, $code) => $this->typesFrais[$code]['ordre']);
            if ($frais->isEmpty()) {
                $this->anomalie('A7', 'Règlement sans détail et inscription sans frais (montant non imputé)', "code_regle {$r->code_regle}");

                continue;
            }
            $du = DB::table('frais_eleves as f')->leftJoin('reductions as r', fn ($j) => $j->on('r.frais_eleve_id', '=', 'f.id')->where('r.is_active', 1))
                ->whereIn('f.id', $frais->values())->groupBy('f.id', 'f.montant_du')->selectRaw('f.id, f.montant_du - coalesce(sum(r.montant),0) net')->pluck('net', 'id');
            foreach ($frais as $id) {
                $part = $id === $frais->last() ? $reste : min($reste, max(0, (int) $du[$id] - ($paye[$id] ?? 0)));
                if ($part > 0) {
                    $lignes[] = ['reglement_id' => $ids[$k], 'frais_eleve_id' => $id, 'dette_id' => null, 'montant' => $part, 'created_at' => $lignesReglements[$k]['date_paiement'], 'updated_at' => now()];
                    $paye[$id] = ($paye[$id] ?? 0) + $part;
                    $reste -= $part;
                }
            }
            $this->anomalie('A7', 'Règlement sans détail V1 (réparti selon l\'ordre des frais)', "code_regle {$r->code_regle}");
        }
        $this->insererLot('reglement_lignes', $lignes);
        $this->compteurs['Règlements'] = count($ids);
        $this->compteurs['Lignes de règlement'] = count($lignes);
        foreach ($fictifs as $an => $total) {
            $this->compteurs['Caisse fictive '.C::anneeScolaire($an).' (F)'] = $total;
        }
    }

    /** Recalcul SQL des montants « cache » (meme regle que les modeles). */
    private function recalculer(): void
    {
        DB::update('UPDATE frais_eleves f JOIN inscriptions i ON i.id = f.inscription_id SET
            f.montant_reduit = (SELECT COALESCE(SUM(r.montant), 0) FROM reductions r WHERE r.frais_eleve_id = f.id AND r.is_active = 1),
            f.montant_paye = (SELECT COALESCE(SUM(l.montant), 0) FROM reglement_lignes l WHERE l.frais_eleve_id = f.id)
            WHERE i.etablissement_id = ?', [$this->etab]);
        DB::update('UPDATE dettes d SET
            d.montant_reduit = (SELECT COALESCE(SUM(r.montant), 0) FROM reductions r WHERE r.dette_id = d.id AND r.is_active = 1),
            d.montant_paye = (SELECT COALESCE(SUM(l.montant), 0) FROM reglement_lignes l WHERE l.dette_id = d.id)
            WHERE d.etablissement_id = ?', [$this->etab]);
        DB::update('UPDATE inscriptions i SET
            i.montant_total_du = (SELECT COALESCE(SUM(f.montant_du), 0) FROM frais_eleves f WHERE f.inscription_id = i.id),
            i.montant_total_reduit = (SELECT COALESCE(SUM(f.montant_reduit), 0) FROM frais_eleves f WHERE f.inscription_id = i.id),
            i.montant_total_paye = (SELECT COALESCE(SUM(f.montant_paye), 0) FROM frais_eleves f WHERE f.inscription_id = i.id)
            WHERE i.etablissement_id = ?', [$this->etab]);
        $depuis = $this->annees[$this->financesDepuis] ?? 0;
        DB::update('UPDATE inscriptions SET statut = CASE
                WHEN classe_id IS NULL THEN ?
                WHEN montant_total_paye <= 0 THEN ?
                WHEN montant_total_du - montant_total_reduit - montant_total_paye <= 0 THEN ?
                ELSE ? END
            WHERE etablissement_id = ? AND annee_scolaire_id >= ?',
            [Inscription::SANS_CLASSE, Inscription::CLASSE_CHOISIE, Inscription::SOLDE, Inscription::PAIEMENT, $this->etab, $depuis]);
    }

    private function depenses(): void
    {
        $v1 = DB::connection('v1');
        $sequences = [];
        $lignes = [];
        $codes = [];
        $ignorees = 0;
        foreach ($v1->table('depense')->get()->sortBy(fn ($d) => [(string) $d->date_dep, (int) $d->code_dep]) as $d) {
            $an = (int) $d->an_dep;
            if ($an < $this->financesDepuis) {
                $ignorees++;

                continue;
            }
            if (! isset($this->annees[$an])) {
                $this->anomalie('DP1', 'Dépense d\'une année inconnue (ignorée)', "code_dep {$d->code_dep}");

                continue;
            }
            $prefixe = C::prefixeNumero($an);
            $sequences[$prefixe] = ($sequences[$prefixe] ?? 0) + 1;
            $lignes[] = [
                'etablissement_id' => $this->etab, 'annee_scolaire_id' => $this->annees[$an],
                'numero' => 'D'.$prefixe.'-'.str_pad((string) $sequences[$prefixe], 5, '0', STR_PAD_LEFT),
                'libelle' => Str::limit(C::texte($d->libele_dep) ?? '(sans libellé)', 297), 'montant' => C::montant($d->montant_dep),
                'source' => 'caisse', 'banque_id' => null, 'retrait_par_id' => null, 'mode_paiement' => 'especes', 'categorie' => 'autres',
                'beneficiaire' => Str::limit((string) C::texte($d->receveur_dep), 147, '') ?: null, 'piece_comptable' => C::texte($d->piece_comptable),
                'payeur_id' => $this->users[$d->payeur_dep ?? ''] ?? $this->users[$d->user_id ?? ''] ?? null,
                'piece_justificative_path' => null, 'date_depense' => C::date($d->date_dep) ?? "$an-10-01",
                'created_at' => C::dateHeure($d->date_create) ?? C::dateHeure($d->date_dep) ?? now(), 'updated_at' => now(),
            ];
            $codes[] = $d->code_dep;
        }
        $ids = $this->insererLot('depenses', $lignes);
        foreach ($codes as $k => $code) {
            $this->lier('depense', $code, 'depenses', $ids[$k]);
        }
        $this->compteurs['Dépenses'] = count($ids);
        $this->compteurs['Dépenses antérieures à '.$this->financesDepuis.' (non reprises)'] = $ignorees;
    }

    private function sms(): void
    {
        $lignes = [];
        foreach (DB::connection('v1')->table('sms')->orderBy('code_sms')->get() as $s) {
            $numero = trim((string) $s->num_sms);
            if ($numero === '') {
                continue;
            }
            $date = C::dateHeure($s->date_sms);
            $lignes[] = [
                'etablissement_id' => $this->etab, 'eleve_id' => $this->eleves[strtoupper(trim((string) $s->matricule))] ?? null,
                'numero' => Str::limit($numero, 20, ''), 'message' => (string) C::texte($s->message), 'campagne' => C::texte($s->campagne),
                'nombre_sms' => 1, 'statut' => (int) $s->envoye === 1 ? 'envoye' : 'echec', 'erreur' => null, 'envoye_par_id' => null,
                'created_at' => $date && $date > '2000' ? $date : null, 'updated_at' => now(),
            ];
        }
        $this->insererLot('sms_messages', $lignes);
        $this->compteurs['SMS (historique)'] = count($lignes);
    }

    /** Logo, armoirie et image par defaut des eleves (decisions Q10, Q11) ; jamais en mode essai. */
    private function images(): void
    {
        $dossier = $this->option('images') ?: storage_path('app/public/logos');
        if (! is_dir($dossier)) {
            $this->compteurs['Images'] = "non copiées (dossier $dossier introuvable)";

            return;
        }
        $parametres = json_decode((string) DB::table('etablissements')->where('id', $this->etab)->value('parametres'), true) ?: [];
        $copiees = [];
        foreach (['logo' => '/^logoEcole\.(png|jpe?g)$/i', 'armoirie' => '/^a(r)?moirie\.(png|jpe?g)$/i', 'photo_eleve_defaut' => '/^photoEleve\.(png|jpe?g)$/i'] as $nom => $motif) {
            $fichier = collect(scandir($dossier) ?: [])->first(fn ($f) => preg_match($motif, $f));
            if (! $fichier) {
                $this->anomalie('IMG', 'Image de l\'école introuvable', $nom);

                continue;
            }
            // Nomme par le code de l'ecole : le disque public est commun a toutes les bases (les id se recoupent).
            $code = DB::table('etablissements')->where('id', $this->etab)->value('code');
            $chemin = ($nom === 'logo' ? 'logos/' : "etablissements/$code/").$code.'-'.$nom.'.'.strtolower(pathinfo($fichier, PATHINFO_EXTENSION));
            Storage::disk('public')->put($chemin, file_get_contents($dossier.DIRECTORY_SEPARATOR.$fichier));
            $copiees[] = $nom;
            if ($nom === 'logo') {
                DB::table('etablissements')->where('id', $this->etab)->update(['logo_path' => $chemin]);
            } else {
                // E11 : en attendant les colonnes, chemins gardes dans les parametres
                $parametres[$nom.'_path'] = $chemin;
            }
        }
        DB::table('etablissements')->where('id', $this->etab)->update(['parametres' => json_encode($parametres)]);
        $this->compteurs['Images'] = implode(', ', $copiees) ?: 'aucune';
    }

    // ------------------------------------------------------------------ Controles

    private function controler(): void
    {
        $v1 = DB::connection('v1');
        $depuis = $this->financesDepuis;

        // Encaissements par annee : V1 (lignes positives des reglements repris, montant du reglement s'il n'a pas de lignes) / V2
        $v1Enc = [];
        $codes = DB::table('migration_v1_correspondances')->where('etablissement_id', $this->etab)->where('table_v1', 'reglement')->pluck('cle_v1')->flip();
        $avecLignes = [];
        foreach ($v1->table('frais as f')->join('reglement as r', 'r.code_regle', '=', 'f.code_regle')->where('f.montant_frais', '>', 0)
            ->whereNotIn('f.type_frais', ['CRAIE', 'RAME'])->select('r.code_regle', 'r.id_inscr', 'f.montant_frais')->cursor() as $l) {
            if (isset($codes[$l->code_regle]) && isset($this->inscriptions[$l->id_inscr])) {
                $an = $this->inscriptions[$l->id_inscr]['an'];
                $v1Enc[$an] = ($v1Enc[$an] ?? 0) + C::montant($l->montant_frais);
                $avecLignes[$l->code_regle] = true;
            }
        }
        foreach ($v1->table('reglement')->where('montant_regle', '>', 0)->get(['code_regle', 'id_inscr', 'montant_regle']) as $r) {
            if (isset($codes[$r->code_regle]) && ! isset($avecLignes[$r->code_regle]) && isset($this->inscriptions[$r->id_inscr])) {
                $an = $this->inscriptions[$r->id_inscr]['an'];
                $v1Enc[$an] = ($v1Enc[$an] ?? 0) + C::montant($r->montant_regle);
            }
        }
        $v2Enc = DB::table('reglement_lignes as l')->join('reglements as r', 'r.id', '=', 'l.reglement_id')->join('annees_scolaires as a', 'a.id', '=', 'r.annee_scolaire_id')
            ->where('r.etablissement_id', $this->etab)->groupBy('a.libelle')->selectRaw('a.libelle, sum(l.montant) m')->pluck('m', 'libelle');
        foreach ($v1Enc as $an => $m) {
            $this->controles[] = ['Encaissé '.C::anneeScolaire($an), $m, (int) ($v2Enc[C::anneeScolaire($an)] ?? 0)];
        }

        // Reste a payer par inscription (dette de l'annee precedente comprise, comme en V1)
        $restesV2 = DB::table('inscriptions as i')->join('annees_scolaires as a', 'a.id', '=', 'i.annee_scolaire_id')
            ->where('i.etablissement_id', $this->etab)
            ->selectRaw('i.id, i.eleve_id, a.libelle, i.montant_total_du - i.montant_total_reduit - i.montant_total_paye reste')->get()->keyBy('id');
        $restesDettes = DB::table('dettes as d')->join('annees_scolaires as a', 'a.id', '=', 'd.annee_scolaire_id')->where('d.etablissement_id', $this->etab)
            ->selectRaw("concat(d.eleve_id, '|', a.libelle) cle, d.montant - d.montant_reduit - d.montant_paye reste")->pluck('reste', 'cle');
        // Paye V1 par inscription (toutes lignes positives, dette comprise ; montant du reglement s'il n'a pas de lignes)
        $payeV1 = [];
        foreach ($v1->table('reglement as r')->leftJoin('frais as f', fn ($j) => $j->on('f.code_regle', '=', 'r.code_regle')->where('f.montant_frais', '>', 0)->whereNotIn('f.type_frais', ['CRAIE', 'RAME']))
            ->groupBy('r.code_regle', 'r.id_inscr', 'r.montant_regle')->selectRaw('r.id_inscr, r.montant_regle, sum(f.montant_frais) lignes')->cursor() as $r) {
            $payeV1[$r->id_inscr] = ($payeV1[$r->id_inscr] ?? 0) + ($r->lignes !== null ? C::montant($r->lignes) : C::montant($r->montant_regle));
        }
        $ecarts = [];
        $incoherents = [];
        $total = [];
        foreach ($this->inscriptions as $idV1 => $ins) {
            if ($ins['an'] < $depuis || (int) $idV1 !== (int) $this->idGarde($ins['id'])) {
                continue;
            }
            $i = $ins['v1'];
            $total[$ins['an']] = ($total[$ins['an']] ?? 0) + 1;
            // La V1 ne compte la dette dans le reste que si elle est rattachee a l'inscription (inscription.dette)
            $dette = C::montant($i->dette) > 0 ? (int) ($restesDettes[$ins['eleve_id'].'|'.C::anneeScolaire($ins['an'] - 1)] ?? 0) : 0;
            $v2 = (int) $restesV2[$ins['id']]->reste + $dette;
            $stocke = C::montant($i->reste);
            $calcule = $i->montant_reduit !== null ? C::montant($i->montant_reduit) - ($payeV1[$idV1] ?? 0) : null;
            if ($v2 === $stocke) {
                continue;
            }
            if ($calcule !== null && $v2 === $calcule) {
                $incoherents[$ins['an']] = ($incoherents[$ins['an']] ?? 0) + 1;
                $this->anomalie('K1a', 'Reste V1 enregistré incohérent (≠ montant réduit − paiements) : la V2 suit le calcul', "id_inscr $idV1 ({$i->matricule}) : V1 enregistré $stocke / calculé et V2 $v2");

                continue;
            }
            $ecarts[$ins['an']] = ($ecarts[$ins['an']] ?? 0) + 1;
            $this->anomalie('K1', 'Reste à payer V2 ≠ reste V1 (à analyser)', "id_inscr $idV1 ({$i->matricule}) : V1 enregistré $stocke, calculé ".($calcule ?? '—')." / V2 $v2");
        }
        foreach ($total as $an => $n) {
            $this->controles[] = ['Restes identiques au reste V1 enregistré '.C::anneeScolaire($an), $n, $n - ($ecarts[$an] ?? 0) - ($incoherents[$an] ?? 0)];
            $this->controles[] = ['… identiques au reste V1 enregistré ou recalculé '.C::anneeScolaire($an), $n, $n - ($ecarts[$an] ?? 0)];
        }

        // Dettes : reste V1 / V2
        $v2Dettes = DB::table('dettes')->where('etablissement_id', $this->etab)->selectRaw('id, montant - montant_reduit - montant_paye reste, is_annulee')->get()->keyBy('id');
        $payeDette = $this->paiementsDettes();
        $ok = 0;
        $okCalcule = 0;
        $n = 0;
        foreach ($v1->table('dette')->whereNotNull('matricule')->where('matricule', '!=', '')->get() as $d) {
            if (! isset($this->dettes[$d->id_dette])) {
                continue;
            }
            $n++;
            $v2 = (int) $v2Dettes[$this->dettes[$d->id_dette]]->reste;
            $attendu = (int) $d->dette_annule === 1 ? 0 : C::montant($d->reste_dette);
            $calcule = (int) $d->dette_annule === 1 ? 0 : C::montant($d->mont_dette) - C::montant($d->reduction) - ($payeDette[$d->id_dette] ?? 0);
            if ($v2 === $attendu) {
                $ok++;
                $okCalcule++;
            } elseif ($v2 === $calcule) {
                $okCalcule++;
                $this->anomalie('K2a', 'Reste de dette V1 enregistré incohérent (≠ montant − réduction − paiements) : la V2 suit le calcul', "id_dette {$d->id_dette} : V1 enregistré $attendu / calculé et V2 $v2");
            } else {
                $this->anomalie('K2', 'Reste de dette V2 ≠ reste V1 (à analyser)', "id_dette {$d->id_dette} : V1 enregistré $attendu, calculé $calcule / V2 $v2");
            }
        }
        $this->controles[] = ['Dettes au reste V1 enregistré', $n, $ok];
        $this->controles[] = ['… au reste V1 enregistré ou recalculé', $n, $okCalcule];

        // Depenses par annee
        $v1Dep = $v1->table('depense')->where('an_dep', '>=', $depuis)->groupBy('an_dep')->selectRaw('an_dep, sum(montant_dep) m')->pluck('m', 'an_dep');
        $v2Dep = DB::table('depenses as d')->join('annees_scolaires as a', 'a.id', '=', 'd.annee_scolaire_id')->where('d.etablissement_id', $this->etab)
            ->groupBy('a.libelle')->selectRaw('a.libelle, sum(d.montant) m')->pluck('m', 'libelle');
        foreach ($v1Dep as $an => $m) {
            $this->controles[] = ['Dépenses '.C::anneeScolaire((int) $an), C::montant($m), (int) ($v2Dep[C::anneeScolaire((int) $an)] ?? 0)];
        }

        // Effectifs
        $this->controles[] = ['Élèves', $v1->table('eleve')->count(), DB::table('eleves')->where('etablissement_id', $this->etab)->count()];
        $this->controles[] = ['Inscriptions (V2 : doublons fusionnés et matricules inconnus exclus, voir A2, A4)', $v1->table('inscription')->count(), DB::table('inscriptions')->where('etablissement_id', $this->etab)->count()];
    }

    // ------------------------------------------------------------------ Outils

    /** Paiements V1 par inscription V2 et type de frais V2 (pour creer les lignes de frais payees). */
    private function paiementsParType(): array
    {
        $resultat = [];
        foreach (DB::connection('v1')->table('frais as f')->join('reglement as r', 'r.code_regle', '=', 'f.code_regle')
            ->where('f.montant_frais', '>', 0)->select('r.id_inscr', 'f.type_frais', 'f.montant_frais')->cursor() as $l) {
            $code = C::TYPES_FRAIS[strtoupper((string) $l->type_frais)] ?? null;
            if ($code && isset($this->inscriptions[$l->id_inscr])) {
                $id = $this->inscriptions[$l->id_inscr]['id'];
                $resultat[$id][$code] = ($resultat[$id][$code] ?? 0) + C::montant($l->montant_frais);
            }
        }

        return $resultat;
    }

    /** Paiements V1 par dette (id_dette V1). */
    private function paiementsDettes(): array
    {
        return DB::connection('v1')->table('frais as f')->join('reglement as r', 'r.code_regle', '=', 'f.code_regle')
            ->where('f.type_frais', 'DETTE')->whereNotNull('r.id_dette')->groupBy('r.id_dette')
            ->selectRaw('r.id_dette, sum(f.montant_frais) m')->pluck('m', 'id_dette')->map(fn ($m) => C::montant($m))->all();
    }

    /** id_inscr V1 de l'inscription gardee pour une inscription V2 (doublons fusionnes). */
    private function idGarde(int $inscriptionId): ?int
    {
        static $gardes = null;
        if ($gardes === null) {
            $gardes = [];
            foreach ($this->inscriptions as $idV1 => $ins) {
                $actuel = $gardes[$ins['id']] ?? null;
                // Meme regle que la fusion : celle qui a des reglements, sinon la plus recente
                if ($actuel === null || $this->prefere($ins['v1'], $this->inscriptions[$actuel]['v1'])) {
                    $gardes[$ins['id']] = $idV1;
                }
            }
        }

        return $gardes[$inscriptionId] ?? null;
    }

    private function prefere(object $a, object $b): bool
    {
        static $avecReglement = null;
        $avecReglement ??= DB::connection('v1')->table('reglement')->distinct()->pluck('id_inscr')->flip();

        return [isset($avecReglement[$a->id_inscr]) ? 1 : 0, (int) $a->id_inscr] > [isset($avecReglement[$b->id_inscr]) ? 1 : 0, (int) $b->id_inscr];
    }

    private function codeType(int $typeFraisId): ?string
    {
        foreach ($this->typesFrais as $code => $t) {
            if ($t['id'] === $typeFraisId) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Insertion par lots de 500 ; renvoie les id dans l'ordre des lignes (un
     * INSERT multi-lignes recoit des id consecutifs : verifie a chaque lot).
     *
     * @return list<int>
     */
    private function insererLot(string $table, array $lignes): array
    {
        static $avecId = [];
        $avecId[$table] ??= Schema::hasColumn($table, 'id');
        $ids = [];
        foreach (array_chunk($lignes, 500) as $lot) {
            DB::table($table)->insert($lot);
            if (! $avecId[$table]) {
                continue;
            }
            $premier = (int) DB::getPdo()->lastInsertId();
            $dernier = $premier + count($lot) - 1;
            if (DB::table($table)->whereBetween('id', [$premier, $dernier])->count() !== count($lot) || (int) DB::table($table)->max('id') !== $dernier) {
                throw new \RuntimeException("Identifiants non consécutifs dans $table : insertion par lots impossible.");
            }
            array_push($ids, ...range($premier, $dernier));
        }

        return $ids;
    }

    /**
     * Niveau V2 d'un niveau V1 (E1) : avec serie au 2nd cycle (« TleA2 » ->
     * TLE A2, cree s'il manque, rattache a TLE), sinon le niveau general.
     */
    private function niveauId(?string $niveauV1): ?int
    {
        [$general, $serie] = C::niveau($niveauV1);
        if (! $general || ! isset($this->niveaux[$general])) {
            return null;
        }
        if (! $serie) {
            return $this->niveaux[$general];
        }
        if (! isset($this->niveaux[$general.$serie])) {
            $g = DB::table('niveaux')->find($this->niveaux[$general]);
            $this->niveaux[$general.$serie] = (int) DB::table('niveaux')->insertGetId([
                'niveau_general_id' => $g->id, 'code' => $general.$serie, 'libelle' => $g->libelle.$serie,
                'cycle' => $g->cycle, 'ordre' => $g->ordre, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $this->niveaux[$general.$serie];
    }

    private function lier(string $tableV1, string|int $cleV1, string $tableV2, int $idV2, array $infos = []): void
    {
        $this->correspondances[] = [
            'etablissement_id' => $this->etab, 'table_v1' => $tableV1, 'cle_v1' => (string) $cleV1,
            'table_v2' => $tableV2, 'id_v2' => $idV2, 'infos' => $infos ? json_encode($infos, JSON_UNESCAPED_UNICODE) : null,
        ];
    }

    private function enregistrerCorrespondances(): void
    {
        foreach (array_chunk($this->correspondances, 1000) as $lot) {
            DB::table('migration_v1_correspondances')->insert($lot);
        }
        $this->compteurs['Correspondances V1 → V2'] = count($this->correspondances);
    }

    private function anomalie(string $code, string $libelle, string $exemple): void
    {
        $a = &$this->anomalies[$code.' '.$libelle];
        $a ??= ['libelle' => $libelle, 'nombre' => 0, 'exemples' => []];
        $a['nombre']++;
        if (count($a['exemples']) < 30) {
            $a['exemples'][] = $exemple;
        }
    }

    private function etape(string $nom, callable $fn): void
    {
        $t = microtime(true);
        $this->output->write("  {$nom}… ");
        $fn();
        $this->line(sprintf('<info>ok</info> (%.1f s)', microtime(true) - $t));
    }

    private function ecrireRapport(string $source, string $cible, float $debut, ?\Throwable $erreur = null): string
    {
        $horodatage = now()->format('Ymd-His');
        $dossier = storage_path('app/private/migration-v1');
        @mkdir($dossier, 0775, true);

        $md = ['# Reprise V1 → V2 — rapport '.now()->format('d/m/Y H:i'), ''];
        $md[] = "- Source : `$source` ; cible : `$cible` ; mode : ".($this->option('essai') ? '**essai (annulé)**' : '**réel**');
        $md[] = "- Finances reprises depuis {$this->financesDepuis} ; durée ".round(microtime(true) - $debut).' s';
        if ($erreur) {
            $md[] = '- **ÉCHEC** : '.$erreur->getMessage().' ('.basename($erreur->getFile()).':'.$erreur->getLine().') — rien n\'a été enregistré.';
        }
        $md[] = '';
        $md[] = '## Volumes';
        $md[] = '';
        $md[] = '| Élément | Nombre |';
        $md[] = '|---|---:|';
        foreach ($this->compteurs as $k => $v) {
            $md[] = "| $k | ".(is_int($v) ? number_format($v, 0, ',', ' ') : $v).' |';
        }
        if ($this->controles) {
            $md[] = '';
            $md[] = '## Contrôles V1 / V2';
            $md[] = '';
            $md[] = '| Contrôle | V1 | V2 | |';
            $md[] = '|---|---:|---:|---|';
            foreach ($this->controles as [$k, $a, $b]) {
                $md[] = "| $k | ".number_format($a, 0, ',', ' ').' | '.number_format($b, 0, ',', ' ').' | '.($a == $b ? 'identique' : '**écart**').' |';
            }
        }
        $md[] = '';
        $md[] = '## Anomalies';
        $md[] = '';
        ksort($this->anomalies);
        foreach ($this->anomalies as $cle => $a) {
            $md[] = '### '.strtok($cle, ' ')." — {$a['libelle']} ({$a['nombre']})";
            $md[] = '';
            foreach ($a['exemples'] as $e) {
                $md[] = "- $e";
            }
            if ($a['nombre'] > count($a['exemples'])) {
                $md[] = '- … et '.($a['nombre'] - count($a['exemples'])).' autres';
            }
            $md[] = '';
        }
        $chemin = "$dossier/rapport-$horodatage.md";
        file_put_contents($chemin, implode("\n", $md));

        if (! $erreur && ! $this->option('essai') && $this->motsDePasse) {
            $f = fopen("$dossier/mots-de-passe-$horodatage.csv", 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Téléphone', 'Nom', 'Prénoms', 'Postes', 'Statut', 'Mot de passe provisoire'], ';');
            foreach ($this->motsDePasse as $l) {
                fputcsv($f, $l, ';');
            }
            fclose($f);
            $this->fichierMotsDePasse = "mots-de-passe-$horodatage.csv";
            $this->warn("Mots de passe provisoires : $dossier/mots-de-passe-$horodatage.csv (à remettre puis supprimer)");
        }

        // Resume pour l'ecran Parametres > Migration V1
        file_put_contents("$dossier/rapport-$horodatage.json", json_encode([
            'date' => now()->toDateTimeString(), 'source' => $source, 'cible' => $cible, 'essai' => (bool) $this->option('essai'),
            'duree' => (int) round(microtime(true) - $debut), 'erreur' => $erreur?->getMessage(),
            'compteurs' => $this->compteurs,
            'controles' => array_map(fn ($c) => ['libelle' => $c[0], 'v1' => $c[1], 'v2' => $c[2]], $this->controles),
            'anomalies' => array_values(array_map(fn ($cle, $a) => ['code' => strtok($cle, ' '), 'libelle' => $a['libelle'], 'nombre' => $a['nombre']], array_keys($this->anomalies), $this->anomalies)),
            'rapport' => basename($chemin), 'mots_de_passe' => $this->fichierMotsDePasse,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->line('Résumé : '.basename($chemin, '.md').'.json');

        return $chemin;
    }
}
