<?php

namespace App\Support\Assistant;

use Illuminate\Support\Str;

/**
 * Connaissances metier de l'assistant (en plus des parcours du Guide) : le
 * systeme educatif ivoirien, la vie scolaire, les evaluations, les finances
 * d'un etablissement prive et les regles exactes de l'application.
 *
 * Chaque theme :
 * - portee : 'national' (regle ou usage du systeme ivoirien, a nuancer selon
 *   l'etablissement), 'application' (regle codee dans Monde Educatif, sure),
 *   'etablissement' (regle par defaut, reglable par l'ecole : verifier avec
 *   l'outil parametres_ecole), 'conseil' (bonne pratique) ;
 * - texte : explication, avec exemples chiffres ;
 * - fiches : procedures du Guide liees ; voir : autres themes.
 *
 * Les seuils et formules viennent du code (App\Support\Resultats,
 * App\Services\CaisseService, App\Support\Reductions...) : les garder
 * synchronises si ces regles changent.
 */
class Savoir
{
    public const DOMAINES = [
        'systeme' => 'Système éducatif ivoirien',
        'scolarite' => 'Scolarité et inscriptions',
        'evaluations' => 'Évaluations et résultats',
        'finances' => 'Finances et caisse',
        'administration' => 'Administration et droits',
        'pratiques' => 'Bonnes pratiques',
    ];

    public const THEMES = [
        // ================================================================ Systeme educatif
        'systeme-educatif' => [
            'domaine' => 'systeme', 'portee' => 'national',
            'titre' => 'Organisation du système éducatif ivoirien',
            'mots' => 'systeme educatif ivoirien cote ivoire organisation cycle cycles prescolaire maternelle primaire college lycee secondaire premier second niveaux classes ministere',
            'texte' => [
                'L’enseignement est placé sous le Ministère de l’Éducation Nationale et de l’Alphabétisation (MENA), représenté dans chaque région par une Direction Régionale (DRENA). Le préscolaire et le primaire sont suivis par les inspections (IEPP).',
                '- **Préscolaire (maternelle)** : Petite, Moyenne et Grande Section.',
                '- **Primaire** : CP1, CP2, CE1, CE2, CM1, CM2. Il se termine par le **CEPE** et l’entrée en 6e.',
                '- **Secondaire, premier cycle (collège)** : 6e, 5e, 4e, 3e. Il se termine par le **BEPC**, puis l’orientation en 2nde.',
                '- **Secondaire, second cycle (lycée)** : 2nde, 1re, Terminale (séries littéraires A, scientifiques C et D…). Il se termine par le **BAC**.',
                'Dans Monde Éducatif, ces niveaux sont communs à tous les établissements ; chaque école crée ses classes dans les niveaux qu’elle ouvre. Au lycée, le **niveau porte la série** (2ndeA, 2ndeC, 1ereA, 1ereD, TleA1, TleA2, TleD…) et il est rattaché à son **niveau général** (2nde, 1ere, Tle).',
                'Les classes portent le nom du niveau suivi du **numéro en exposant** : 6eme¹, 6eme², TleA2³ (l’exposant distingue le numéro de la classe du chiffre de la série : TleA1² = 2e classe de TleA1).',
            ],
            'fiches' => ['classes'], 'voir' => ['cycles-application', 'examens-nationaux'],
        ],
        'cycles-application' => [
            'domaine' => 'systeme', 'portee' => 'application',
            'titre' => 'Cycles et niveaux dans l’application',
            'mots' => 'cycle maternelle primaire premier cycle second cycle filtre cycle niveau 6eme 5eme 4eme 3eme 2nde 1ere tle terminale serie series',
            'texte' => [
                'L’application regroupe les 16 niveaux en 4 cycles, utilisés dans tous les filtres (points, caisse, classes, rapports) :',
                '- **Maternelle** : Petite, Moyenne, Grande Section ;',
                '- **Primaire** : CP1 à CM2 ;',
                '- **Premier cycle** : 6eme à 3eme ;',
                '- **Second cycle** : 2nde, 1ere, Tle et leurs séries (2ndeA, 2ndeC, 1ereA, 1ereD, TleA1, TleA2, TleD…).',
                'Dans les filtres, on choisit un cycle OU un niveau OU une classe (un seul des trois à la fois).',
            ],
            'fiches' => ['classes'], 'voir' => ['systeme-educatif'],
        ],
        'examens-nationaux' => [
            'domaine' => 'systeme', 'portee' => 'national',
            'titre' => 'Examens nationaux (CEPE, BEPC, BAC)',
            'mots' => 'examen examens cepe bepc bac baccalaureat droit examen candidat classe examen cm2 3eme terminale',
            'texte' => [
                '- **CEPE** (fin du CM2) : certificat d’études primaires, avec l’entrée en 6e.',
                '- **BEPC** (fin de 3e) : brevet du premier cycle ; il conditionne l’orientation en 2nde.',
                '- **BAC** (fin de Terminale) : baccalauréat.',
                'Les classes d’examen (CM2, 3e, Tle) paient souvent un **droit d’examen** : dans l’application c’est un type de frais annexe (« Droit d’examen ») que l’école fixe par niveau dans Paramètres › Paiements.',
            ],
            'fiches' => [], 'voir' => ['types-frais', 'systeme-educatif'],
        ],
        'eleve-affecte' => [
            'domaine' => 'systeme', 'portee' => 'national',
            'titre' => 'Élève affecté et élève non affecté',
            'mots' => 'affecte affectes affectee non affecte etat orientation subvention prive statut scolarite gratuite boursier etat',
            'texte' => [
                'Un élève **affecté** a été orienté par l’État dans l’établissement (en général à l’entrée en 6e ou en 2nde), quand les places du public ne suffisent pas. L’État prend en charge sa scolarité auprès de l’établissement privé.',
                'Un élève **non affecté** est inscrit à la demande de sa famille, qui paie la scolarité.',
                'Conséquences dans l’application :',
                '- les montants sont fixés **par niveau et par statut** (deux grilles : affectés / non affectés) ;',
                '- la **scolarité n’est pas due par un affecté** (frais « non applicable aux affectés ») : il paie l’inscription et les frais annexes ;',
                '- tous les tableaux financiers séparent les colonnes Affectés / Non affectés.',
                'Le statut se choisit à l’inscription et peut se corriger tant que l’élève n’a rien payé.',
            ],
            'fiches' => ['inscription', 'parametres-paiements'], 'voir' => ['grilles-tarifaires', 'types-frais'],
        ],
        'matricule-national' => [
            'domaine' => 'systeme', 'portee' => 'national',
            'titre' => 'Matricule national de l’élève',
            'mots' => 'matricule national identifiant numero eleve format chiffres lettre 18583394p unique ministere',
            'texte' => [
                'Chaque élève a un **matricule national** attribué par le ministère : il le suit pendant toute sa scolarité et d’un établissement à l’autre.',
                'L’application ne crée jamais de matricule : on saisit celui de l’élève. Les formats acceptés sont réglés dans Paramètres › Inscriptions (par défaut **8 chiffres + 1 lettre**, ex. 18583394P ; « 9 » = chiffre, « A » = lettre).',
                'Le matricule sert partout : recherche, encaissement, dossier, reçu de l’inscription en ligne. Un élève déjà connu (année précédente) se retrouve par son matricule et se réinscrit sans ressaisir sa fiche.',
            ],
            'fiches' => ['inscription', 'parametres-inscriptions'], 'voir' => ['inscription-en-ligne'],
        ],
        'inscription-en-ligne' => [
            'domaine' => 'systeme', 'portee' => 'national',
            'titre' => 'Inscription en ligne (site de l’État)',
            'mots' => 'inscription en ligne site etat recu preinscription sigfne mena code mena verification mise a jour en ligne paiement en ligne decision',
            'texte' => [
                'Au secondaire, l’élève fait chaque année son inscription en ligne sur la plateforme du ministère et obtient un **reçu** (numéro de reçu, somme payée, décision de fin d’année, établissement).',
                'Dans l’application, à la saisie du matricule, le reçu est consulté automatiquement (si « Vérification en ligne » est activée dans Paramètres › Inscriptions) pour **pré-remplir et comparer** la fiche (nom, date de naissance, décision…) et vérifier que le reçu concerne bien votre établissement (code MENA).',
                'Le bouton **« Mise à jour inscription en ligne »** relance la vérification pour les élèves qui n’avaient pas encore fait leur inscription en ligne.',
                'L’inscription en ligne ne remplace pas l’inscription dans l’établissement : les frais de l’école se paient à la caisse.',
            ],
            'fiches' => ['inscription', 'parametres-inscriptions'], 'voir' => ['matricule-national', 'decisions-fin-annee'],
        ],
        'decisions-fin-annee' => [
            'domaine' => 'systeme', 'portee' => 'application',
            'titre' => 'Décisions de fin d’année et passage',
            'mots' => 'decision fin annee admis redouble redoublant exclu passage classe superieure niveau suivre conseil de classe reinscription',
            'texte' => [
                'La décision de fin d’année est **ADMIS** (passe au niveau suivant), **REDOUBLE** (reste au même niveau, l’élève est alors « redoublant ») ou **EXCLU**.',
                'Elle s’appuie sur la moyenne annuelle et le conseil de classe. Dans l’application elle est saisie (ou reprise du reçu en ligne) à la **réinscription** de l’élève l’année suivante, avec le niveau à suivre.',
                'Un élève qui redouble est marqué **redoublant** (« doublant ») : filtre et capsules « Doublants / Non doublants » dans les listes de classe, critère « Redoublant » dans les points.',
            ],
            'fiches' => ['inscription'], 'voir' => ['moyenne-annuelle', 'reinscription'],
        ],
        'annee-periodes' => [
            'domaine' => 'systeme', 'portee' => 'etablissement',
            'titre' => 'Année scolaire, trimestres et semestres',
            'mots' => 'annee scolaire trimestre trimestres semestre semestres periode periodes decoupage cloture rentree septembre juillet',
            'texte' => [
                'L’année scolaire va en général de septembre à juillet. Elle est découpée en **3 trimestres** ou **2 semestres**, au choix de l’établissement (Paramètres › Années scolaires).',
                'Chaque période a ses dates, peut être **active** (période en cours) et **clôturée** (plus de saisie de notes).',
                'À la connexion, on travaille sur une année et, si le poste a le droit « Choisir la période », sur la période choisie ; sinon sur la période en cours.',
            ],
            'fiches' => ['annees', 'arret-notes'], 'voir' => ['moyenne-annuelle', 'arret-cloture'],
        ],
        'acteurs-vie-scolaire' => [
            'domaine' => 'systeme', 'portee' => 'national',
            'titre' => 'Qui fait quoi dans un établissement',
            'mots' => 'educateur professeur principal directeur etudes fondateur econome comptable caissier secretaire role metier vie scolaire',
            'texte' => [
                '- **Fondateur** : propriétaire de l’établissement privé, accès à tout.',
                '- **Directeur des études** : pédagogie (classes, matières, coefficients, notes, arrêt des notes, bulletins) ; il signe les bulletins.',
                '- **Éducateur** : vie scolaire de ses niveaux (inscriptions, absences, note de conduite, discipline).',
                '- **Professeur** : enseigne ses matières dans ses classes et saisit leurs notes. Le **professeur principal** est le référent d’une classe.',
                '- **Caissier** : encaisse les paiements et remet les reçus.',
                '- **Comptable / Économe / Directeur administratif** : suivent les paiements, les dépenses et la trésorerie.',
                '- **Secrétaire** : accueil et inscriptions. **Parent** : suit la scolarité et les paiements de ses enfants.',
                'Dans l’application, chacun de ces rôles est un **poste** dont les droits se règlent dans Administration › Rôles.',
            ],
            'fiches' => ['roles', 'utilisateurs'], 'voir' => ['postes-droits', 'portee-donnees'],
        ],

        // ================================================================ Scolarite (application)
        'statuts-inscription' => [
            'domaine' => 'scolarite', 'portee' => 'application',
            'titre' => 'Statuts d’une inscription (soldé, en attente…)',
            'mots' => 'statut statuts inscription attente classe premier versement solder solde etat eleve liste classe visible apparait',
            'texte' => [
                'Une inscription passe par 4 statuts :',
                '- **En attente de choix de classe** (0) : inscrit dans un niveau, sans classe (ses frais ne sont pas encore fixés) ;',
                '- **En attente du 1er versement** (1) : placé en classe, frais fixés, rien payé ;',
                '- **En attente de solder** (2) : au moins un paiement, il reste à payer ;',
                '- **Soldé** (3) : tout est payé (réductions comprises).',
                'Le statut change tout seul (choix de la classe, encaissement, réduction, correction d’un paiement).',
                'Un élève n’apparaît dans la liste de sa classe (écran, PDF et effectifs) qu’à partir d’un statut réglé dans Paramètres › Paramètres inscriptions : classe attribuée, **1er paiement (par défaut)** ou soldé.',
                'Sur la fiche d’une inscription **en attente de choix de classe**, le bouton « Modifier » s’appelle **« Terminer inscription »**. Sur une inscription **soldée**, le bouton « Encaisser » devient **« Paiements »** (il mène au même écran).',
                'Quand un éducateur change le niveau ou le statut (affecté) d’un élève en attente du 1er versement, ses frais sont recalculés ; si le nouveau niveau n’a pas de classe choisie, la classe est retirée et l’inscription repasse **en attente de choix de classe**. Si tous les paiements d’un élève sont supprimés, il repasse **en attente du 1er versement**.',
            ],
            'fiches' => ['inscription', 'parametres-inscriptions', 'encaisser'], 'voir' => ['frais-fixes-inscription', 'reste-a-payer'],
        ],
        'frais-fixes-inscription' => [
            'domaine' => 'scolarite', 'portee' => 'application',
            'titre' => 'Pourquoi les frais sont fixés au choix de la classe',
            'mots' => 'frais fixes copie grille changement tarif montant du inscription classe pourquoi montant different',
            'texte' => [
                'Quand l’élève est placé en classe, les montants de la grille de son niveau et de son statut (affecté ou non) sont **copiés dans son inscription**.',
                'Si la grille change ensuite, les élèves déjà placés gardent leurs montants : on ne modifie pas en cours d’année ce qu’une famille doit déjà.',
                'Tant qu’aucun paiement n’est fait, changer le niveau ou le statut recalcule ses frais. Annuler une inscription la supprime avec ses frais ; la fiche de l’élève reste.',
            ],
            'fiches' => ['inscription', 'parametres-paiements'], 'voir' => ['grilles-tarifaires', 'statuts-inscription'],
        ],
        'reinscription' => [
            'domaine' => 'scolarite', 'portee' => 'application',
            'titre' => 'Réinscription et dettes de l’année précédente',
            'mots' => 'reinscription reinscrire ancien eleve annee precedente dette report reste impaye nouvelle annee',
            'texte' => [
                'Un ancien élève se réinscrit par son matricule : sa fiche est reprise, on saisit la décision de l’année précédente et le niveau à suivre.',
                'S’il restait de l’argent à payer l’année précédente, ce reste devient automatiquement une **dette** de l’élève, payable à part de ses frais de l’année.',
            ],
            'fiches' => ['inscription', 'dettes'], 'voir' => ['dettes', 'decisions-fin-annee'],
        ],
        'classes-effectifs' => [
            'domaine' => 'scolarite', 'portee' => 'etablissement',
            'titre' => 'Classes, numérotation et effectifs maximums',
            'mots' => 'classe classes numerotation chiffres lettres exposant nom classe effectif maximum limite complete capacite salle professeur principal educateur',
            'texte' => [
                '- **Nom** : niveau + numéro en exposant (6eme¹, 6eme², TleA2³) ; en numérotation par lettres : 6eme A, 6eme B (Paramètres › Paramètres classes).',
                '- **Effectif maximum** : une limite de **classe** l’emporte sur celle du **niveau**, qui l’emporte sur la limite **générale**. Une classe pleine ne peut plus recevoir d’élève.',
                '- Une classe qui a des élèves ne peut pas être supprimée.',
                '- Chaque classe peut avoir une salle et une LV2. Le **professeur principal** se choisit dans la liste de la classe, bloc « Professeurs de la classe ». L’**éducateur** affiché est celui qui suit le niveau de la classe cette année (niveaux donnés dans Administration › Utilisateurs).',
            ],
            'fiches' => ['classes', 'liste-classe'], 'voir' => ['lv2'],
        ],
        'lv2' => [
            'domaine' => 'scolarite', 'portee' => 'national',
            'titre' => 'Langue vivante 2 (allemand, espagnol)',
            'mots' => 'lv2 langue vivante allemand espagnol deuxieme langue choix langue',
            'texte' => [
                'Au collège, la deuxième langue vivante (LV2) est en général l’**allemand** ou l’**espagnol**, choisie par l’élève (le plus souvent à partir de la 4e).',
                'Dans l’application, la LV2 se choisit à l’inscription de l’élève et peut être donnée à une classe. Pour les notes de LV2, seuls les élèves inscrits avec cette langue sont notés.',
            ],
            'fiches' => ['classes', 'notes'], 'voir' => ['classes-effectifs'],
        ],
        'portee-donnees' => [
            'domaine' => 'administration', 'portee' => 'application',
            'titre' => 'Ce que chacun voit (portée des données)',
            'mots' => 'voir eleves niveaux educateur professeur parent enfants classes portee limite pourquoi je ne vois pas aucun niveau',
            'texte' => [
                '- **Éducateur** (poste rattaché à des niveaux) : uniquement les niveaux qui lui sont attribués pour l’année. **Sans niveau attribué, il ne voit aucun élève** : l’administrateur doit lui donner ses niveaux (Administration › Utilisateurs).',
                '- **Professeur** : ses classes d’enseignement (et celle dont il est professeur principal) ; il ne saisit que les notes de ses matières.',
                '- **Parent** : seulement ses enfants.',
                '- **Comptable** : consulte la liste des inscrits sans pouvoir la modifier. La liste des inscriptions ne montre jamais de montants (ils sont sur la fiche d’inscription, la caisse et le reste à payer).',
                '- **Caissier** : ses propres encaissements dans la caisse et la liste des paiements.',
                '- Les autres postes voient tout ce que leurs droits permettent.',
            ],
            'fiches' => ['utilisateurs', 'roles'], 'voir' => ['postes-droits'],
        ],

        // ================================================================ Evaluations
        'notes-baremes' => [
            'domaine' => 'evaluations', 'portee' => 'application',
            'titre' => 'Notes, barèmes et moyenne d’une matière',
            'mots' => 'note notes bareme sur 10 sur 20 sur 40 evaluation devoir interrogation composition moyenne matiere calcul poids',
            'texte' => [
                'Une évaluation est notée **sur 10, 20 ou 40**. Son poids dans la moyenne est son barème divisé par 20 (sur 10 : 0,5 ; sur 20 : 1 ; sur 40 : 2).',
                '**Moyenne de la matière = somme des notes ÷ somme des poids.**',
                'Exemple : 7/10, 14/20 et 30/40 → (7 + 14 + 30) ÷ (0,5 + 1 + 2) = 51 ÷ 3,5 = **14,57/20**.',
                'Une moyenne reste **provisoire** tant que la matière n’est pas arrêtée : elle change à chaque nouvelle note.',
            ],
            'fiches' => ['notes', 'resultats'], 'voir' => ['moyenne-generale', 'arret-cloture'],
        ],
        'moyenne-generale' => [
            'domaine' => 'evaluations', 'portee' => 'application',
            'titre' => 'Moyenne générale, coefficients et bilans',
            'mots' => 'moyenne generale coefficient coefficients calcul bilan lettres sciences litteraire scientifique matiere sans note',
            'texte' => [
                '**Moyenne générale = somme (moyenne de la matière × coefficient) ÷ somme des coefficients**, en ne comptant que les matières où l’élève a une moyenne.',
                'Exemple : Français 12 (coef 3), Maths 15 (coef 3), Anglais 10 (coef 2), Conduite 16 (coef 1) → (36 + 45 + 20 + 16) ÷ 9 = **13,00**.',
                'La **conduite** compte comme une matière de coefficient 1. Les **bilans lettres et sciences** reprennent le même calcul sur les matières littéraires et scientifiques.',
                'Les coefficients se règlent par niveau dans Paramètres › Matières et coefficients.',
            ],
            'fiches' => ['resultats', 'matieres'], 'voir' => ['notes-baremes', 'conduite', 'rangs'],
        ],
        'moyenne-annuelle' => [
            'domaine' => 'evaluations', 'portee' => 'application',
            'titre' => 'Moyenne annuelle',
            'mots' => 'moyenne annuelle annee calcul trimestre semestre poids double t1 t2 t3 passage',
            'texte' => [
                'La première période compte **une fois**, les suivantes **deux fois** :',
                '- en trimestres : **(T1 + 2 × T2 + 2 × T3) ÷ 5** ;',
                '- en semestres : **(S1 + 2 × S2) ÷ 3**.',
                'Exemple : T1 = 10, T2 = 12, T3 = 14 → (10 + 24 + 28) ÷ 5 = **12,40**.',
                'Le calcul s’applique aussi à chaque matière (moyenne annuelle de la matière).',
            ],
            'fiches' => ['resultats'], 'voir' => ['decisions-fin-annee', 'moyenne-generale'],
        ],
        'rangs' => [
            'domaine' => 'evaluations', 'portee' => 'application',
            'titre' => 'Rangs et ex aequo',
            'mots' => 'rang rangs classement premier ex aequo meme moyenne non classe major',
            'texte' => [
                'Les élèves sont classés par moyenne décroissante. **Même moyenne = même rang (ex aequo)**, et le rang suivant saute : deux élèves 3e ex aequo, le suivant est 5e.',
                'Un élève sans moyenne n’est pas classé. Il y a aussi un rang par matière.',
            ],
            'fiches' => ['resultats'], 'voir' => ['moyenne-generale'],
        ],
        'appreciations' => [
            'domaine' => 'evaluations', 'portee' => 'application',
            'titre' => 'Appréciations automatiques',
            'mots' => 'appreciation appreciations excellent tres bien bien assez bien passable insuffisant mediocre conseil de classe bulletin seuil',
            'texte' => [
                'Par matière (sur 20) : **Excellent** ≥ 18, **Très bien** ≥ 16, **Bien** ≥ 14, **Assez bien** ≥ 12, **Passable** ≥ 10, **Insuffisant** ≥ 8, **Très insuffisant** ≥ 6, **Médiocre** en dessous.',
                'Conseil de classe (moyenne générale) : Excellent travail ≥ 18, Très bon travail ≥ 16, Bon travail ≥ 14, Assez bon travail ≥ 12, Travail passable ≥ 10, Travail insuffisant ≥ 8,5, Mauvais travail en dessous.',
            ],
            'fiches' => ['resultats'], 'voir' => ['distinctions-sanctions'],
        ],
        'distinctions-sanctions' => [
            'domaine' => 'evaluations', 'portee' => 'application',
            'titre' => 'Tableau d’honneur, encouragements, félicitations, avertissements et blâmes',
            'mots' => 'tableau honneur encouragements felicitations distinction distinctions avertissement blame sanction sanctions travail conduite seuil',
            'texte' => [
                'Distinctions (moyenne générale) :',
                '- **Tableau d’honneur** à partir de 12 ;',
                '- **+ Encouragements** à partir de 14 ;',
                '- **+ Félicitations** à partir de 16.',
                'Sanctions :',
                '- travail : **Avertissement** sous 10, **Blâme** sous 8,5 ;',
                '- conduite : **Avertissement** sous 14, **Blâme** sous 10.',
                'Elles apparaissent sur les résultats, les bulletins et les rapports (tableau d’honneur, élèves en difficulté).',
            ],
            'fiches' => ['resultats', 'rapports'], 'voir' => ['conduite', 'appreciations'],
        ],
        'conduite' => [
            'domaine' => 'evaluations', 'portee' => 'application',
            'titre' => 'Note de conduite',
            'mots' => 'conduite note conduite discipline educateur comportement cond',
            'texte' => [
                'La **conduite** est notée sur 20 par l’**éducateur** (droit « Absences »), une note par élève et par période. L’éducateur ne saisit **que** la conduite : les notes des matières sont saisies par les professeurs, ou à leur place par un poste qui a le droit **« Saisir les notes pour les professeurs »** (informaticien par défaut, donnable à tout poste dans Administration › Rôles).',
                'Elle compte dans la moyenne générale avec un **coefficient 1** et déclenche les sanctions de conduite (avertissement sous 14, blâme sous 10).',
            ],
            'fiches' => ['absences', 'notes'], 'voir' => ['distinctions-sanctions', 'absences-assiduite'],
        ],
        'arret-cloture' => [
            'domaine' => 'evaluations', 'portee' => 'application',
            'titre' => 'Arrêter les notes et clôturer une période',
            'mots' => 'arret arreter notes cloturer cloture periode figer provisoire definitive bulletin directeur',
            'texte' => [
                '**Arrêter** une matière (ou toutes les matières d’une classe) fige ses moyennes : elles ne bougent plus et les bulletins deviennent définitifs. On peut rouvrir si besoin.',
                '**Clôturer** une période arrête toutes les classes et bloque toute nouvelle saisie de notes sur la période.',
                'C’est réservé au droit « Arrêter les notes » (directeur des études par défaut), dans Paramètres › Arrêt des notes.',
            ],
            'fiches' => ['arret-notes'], 'voir' => ['notes-baremes'],
        ],
        'bulletins' => [
            'domaine' => 'evaluations', 'portee' => 'application',
            'titre' => 'Contenu d’un bulletin',
            'mots' => 'bulletin bulletins contenu imprimer moyenne rang appreciation professeur signature',
            'texte' => [
                'Le bulletin de période reprend, pour chaque matière : moyenne, coefficient, points, rang et appréciation ; puis la moyenne générale, le rang, l’effectif, les moyennes de classe, les bilans lettres et sciences, la distinction ou sanction et l’appréciation du conseil.',
                'Le bulletin annuel reprend les moyennes de chaque période et la moyenne annuelle.',
                'Les moyennes non arrêtées y sont provisoires : arrêtez les notes avant l’impression définitive.',
                'Signatures : **le professeur principal** et **le directeur des études** (son nom est repris automatiquement du compte qui a ce poste).',
            ],
            'fiches' => ['resultats'], 'voir' => ['arret-cloture', 'moyenne-annuelle'],
        ],
        'absences-assiduite' => [
            'domaine' => 'evaluations', 'portee' => 'application',
            'titre' => 'Absences et assiduité',
            'mots' => 'absence absences retard retards heures justifiee justifier motif assiduite appel',
            'texte' => [
                'Une absence se saisit en **heures**, pour un élève, une date et une période ; elle peut être **justifiée** avec un motif.',
                'Les totaux (heures, justifiées, non justifiées) apparaissent par élève et par classe, et dans les rapports d’assiduité.',
                'Les absences ne modifient pas la moyenne : c’est la note de conduite, saisie par l’éducateur, qui traduit le comportement.',
            ],
            'fiches' => ['absences'], 'voir' => ['conduite'],
        ],

        // ================================================================ Finances
        'types-frais' => [
            'domaine' => 'finances', 'portee' => 'etablissement',
            'titre' => 'Types de frais',
            'mots' => 'frais types frais inscription scolarite annexes annexe en nature craie rame tenue macaron photo cantine obligatoire facultatif',
            'texte' => [
                '- **Frais principaux** : **inscription** et **scolarité** (la scolarité n’est pas due par un élève affecté).',
                '- **Frais annexes** : frais annexes, droit d’examen, livret scolaire, photo, tenue EPS, tee-shirt, macaron, carte d’accès, informatique, cantine… Chaque école active ceux qu’elle utilise.',
                '- **Frais en nature** : craie, rame de papier… à **apporter** (quantité cochée à la remise), pas à payer en argent.',
                'Chaque type peut être obligatoire ou non, et applicable aux affectés et / ou aux non affectés (Paramètres › Paiements).',
            ],
            'fiches' => ['parametres-paiements'], 'voir' => ['grilles-tarifaires', 'ordre-paiement'],
        ],
        'grilles-tarifaires' => [
            'domaine' => 'finances', 'portee' => 'etablissement',
            'titre' => 'Grilles tarifaires et minimum d’inscription',
            'mots' => 'grille grilles tarif tarifs montant montants niveau minimum inscription premier versement cout prix combien',
            'texte' => [
                'En début d’année, l’école fixe pour **chaque niveau et chaque statut** (affecté / non affecté) le montant de chaque frais (Paramètres › Paramètres paiements).',
                'Le **minimum à verser pour valider l’inscription** (1er versement) est calculé automatiquement : les **frais annexes** pour un affecté, les **frais annexes + l’inscription** pour un non affecté.',
                'Exemple : annexes 20 000 et inscription 15 000 → minimum 20 000 pour un affecté, 35 000 pour un non affecté.',
                'Sans grille définie pour un niveau, on ne peut pas y inscrire d’élève.',
                'Pour connaître les montants réels de votre école, demandez-moi « les tarifs de l’école » : je les consulte.',
            ],
            'fiches' => ['parametres-paiements'], 'voir' => ['frais-fixes-inscription', 'versements'],
        ],
        'ordre-paiement' => [
            'domaine' => 'finances', 'portee' => 'etablissement',
            'titre' => 'Ordre de paiement des frais (répartition d’un versement)',
            'mots' => 'ordre paiement priorite repartition versement repartir frais annexes avant scolarite pourquoi annexes payes',
            'texte' => [
                'Un versement est réparti **automatiquement** sur les frais restant à payer, dans l’ordre réglé dans Paramètres › Ordre de paiement.',
                'Exemple avec l’ordre annexes → inscription → scolarité : pour des annexes de 20 000, une inscription de 15 000 et une scolarité de 60 000, un versement de **40 000** règle 20 000 d’annexes, 15 000 d’inscription et **5 000 de scolarité**.',
                'Le caissier ne choisit pas les frais : il saisit le montant, l’application répartit.',
            ],
            'fiches' => ['ordre-paiement', 'encaisser'], 'voir' => ['versements'],
        ],
        'versements' => [
            'domaine' => 'finances', 'portee' => 'etablissement',
            'titre' => 'Règles des versements',
            'mots' => 'versement versements minimum maximum tranche tranches nombre six 6 dernier solder montant refuse premier versement 1000',
            'texte' => [
                '- **1er versement** : au moins le **minimum d’inscription** de la grille (annexes pour un affecté, annexes + inscription pour un non affecté).',
                '- **Versements suivants** : au moins **1 000 F** (réglable).',
                '- **6 versements au plus** (réglable) : le dernier autorisé doit **solder** tout le reste.',
                '- Jamais plus que le reste à payer. L’élève doit être placé en classe (frais fixés).',
                '- La dette d’une année précédente se paie à part, dans le même encaissement.',
                'Chaque versement est numéroté (1er, 2e…) et donne un reçu.',
            ],
            'fiches' => ['encaisser'], 'voir' => ['ordre-paiement', 'dettes', 'recu-sms'],
        ],
        'reste-a-payer' => [
            'domaine' => 'finances', 'portee' => 'application',
            'titre' => 'Calcul du reste à payer',
            'mots' => 'reste payer reste a payer combien doit montant du reduit paye solde impaye recouvrement',
            'texte' => [
                '**Reste à payer = montant dû − réductions − déjà payé**, par frais et au total. Les dettes des années précédentes s’ajoutent à part.',
                'Exemple : dû 70 000, réduction 10 000, payé 35 000 → reste **25 000**.',
                'La page Finances › Reste à payer liste les élèves placés en classe qui doivent encore, avec filtres. Le PDF « Imprimer avec stats » commence par les statistiques (par genre, statut, redoublement, cycle, niveau et classe : nombre d’élèves par étape et montants à payer, payé, reste), puis la liste classe par classe.',
            ],
            'fiches' => ['reste-a-payer'], 'voir' => ['reductions-cas-sociaux', 'dettes'],
        ],
        'reductions-cas-sociaux' => [
            'domaine' => 'finances', 'portee' => 'application',
            'titre' => 'Réductions et cas sociaux',
            'mots' => 'reduction reductions cas social cas sociaux remise rabais gratuite donneur ordre motif exoneration bourse',
            'texte' => [
                '- **Réduction** : porte sur les **frais principaux** (inscription, scolarité), **1 000 F minimum**.',
                '- **Cas social** : peut aller jusqu’à la **totalité** des frais ; il couvre d’abord les frais principaux, puis les annexes.',
                'Dans chaque groupe, le frais qui serait payé en dernier (selon l’ordre de paiement) est réduit en premier.',
                'Le **donneur d’ordre** (qui a accordé la réduction) est obligatoire et reste tracé. Une réduction peut aussi porter sur une dette, ou l’**annuler** entièrement.',
                'Les cas sociaux sont comptés à part dans le point des inscrits. Le point général des réductions ne compte les réductions de dette que pour les années de dette prises en compte (Paramètres › Dettes).',
            ],
            'fiches' => ['reduction'], 'voir' => ['reste-a-payer', 'points-bilans'],
        ],
        'dettes' => [
            'domaine' => 'finances', 'portee' => 'application',
            'titre' => 'Dettes des années précédentes',
            'mots' => 'dette dettes annee precedente ancien impaye arriere report annuler annulation recouvrement n-1 annees prises en compte',
            'texte' => [
                'Une **dette** est ce qu’un élève n’a pas payé une année précédente. Elle est créée à sa réinscription, reste rattachée à l’année d’origine et n’est **jamais ajoutée au montant de l’inscription** : elle est suivie à part.',
                'Seules les dettes des **N dernières années** comptent (caisse, reste à payer, liste et point des dettes, réductions) : réglage Paramètres › Dettes, **1 par défaut = seulement le reste de l’année précédente (n-1)**. Le dossier de l’élève garde tout l’historique.',
                'Elle se paie à la caisse **à part** des frais de l’année (montant « dette » de l’encaissement), la plus ancienne d’abord.',
                'On peut la réduire ou l’**annuler** (droit « Réductions »), avec un donneur d’ordre.',
                'Suivi : Finances › Dettes (liste) et Bilans › Point dettes (dettes encaissées, situation à ce jour).',
            ],
            'fiches' => ['dettes', 'encaisser'], 'voir' => ['reinscription', 'points-bilans'],
        ],
        'recu-sms' => [
            'domaine' => 'finances', 'portee' => 'application',
            'titre' => 'Reçu de paiement et SMS au parent',
            'mots' => 'recu recus numero imprimer exemplaires sms parent message notification billet',
            'texte' => [
                'Chaque encaissement donne un **reçu numéroté**, imprimé en **2 exemplaires** sur une page (un pour la famille, un pour l’école), suivi en page 2 du **billet d’autorisation d’entrée en classe** (voir le thème « Billet d’entrée en classe »). Il se réimprime à tout moment depuis le paiement.',
                'Après l’encaissement, un **SMS** est envoyé au parent : contact principal, sinon père, mère ou tuteur (si les SMS sont activés et qu’il reste du crédit, Paramètres › SMS).',
            ],
            'fiches' => ['encaisser'], 'voir' => ['correction-paiement'],
        ],
        'correction-paiement' => [
            'domaine' => 'finances', 'portee' => 'application',
            'titre' => 'Corriger ou supprimer un paiement',
            'mots' => 'corriger modifier supprimer paiement erreur caisse motif historique trace annuler paiement',
            'texte' => [
                'Un paiement erroné se **modifie** ou se **supprime** (icônes crayon et poubelle) avec un **motif obligatoire**. L’ancien et le nouveau montant restent dans l’historique, avec l’auteur et la date.',
                'Le reste à payer, le statut et les points se recalculent. Droit nécessaire : « Modifier / supprimer un paiement ».',
            ],
            'fiches' => ['modifier-paiement'], 'voir' => ['versements'],
        ],
        'points-bilans' => [
            'domaine' => 'finances', 'portee' => 'application',
            'titre' => 'Point de caisse, point des inscrits, point des dettes',
            'mots' => 'point points bilan bilans caisse inscrits dettes passes caisse cas sociaux affectes impression generale total general tous caissier educateur',
            'texte' => [
                '- **Caisse (point de caisse)** : montants encaissés par type de frais et statut (affectés / non affectés), sur une journée, des dates, un mois ou l’année.',
                '- **Point des inscrits** : nombre d’élèves inscrits sur la période, selon qu’ils sont **passés à la caisse** (au moins un paiement), **cas sociaux** ou **pas passés à la caisse**.',
                '- **Point des dettes** : dettes encaissées sur la période (par année d’origine) et situation des dettes à ce jour.',
                'Filtres **Caissier** (caisse) et **Éducateur** (point des inscrits) : « Total général » par défaut, un agent, ou « Tous » (total puis un tableau par agent). Un caissier ne voit que ses encaissements ; un éducateur ne voit pas le filtre.',
                'L’**impression générale** détaille par genre, niveau, classe et agent. Le **bilan général de caisse** (PDF) donne : 1. bilan général, **2. bilan par caissier**, puis genre, redoublement, cycle, niveau et classe.',
                'Dans le point des inscrits, l’œil d’une case ouvre la liste des élèves ; son PDF donne le **statut de l’inscription** (sans montants).',
            ],
            'fiches' => ['point-caisse', 'point-inscriptions'], 'voir' => ['eleve-affecte', 'reductions-cas-sociaux'],
        ],
        'entrees-caisse-banque' => [
            'domaine' => 'finances', 'portee' => 'regle',
            'titre' => 'Entrées caisse et entrées banque',
            'mots' => 'entree entrees recette caisse banque versement subvention don transfert total entrees caissier solde banque',
            'texte' => [
                '- **Entrées caisse** = les **paiements des élèves** (frais de l’année et dettes). Elles ne se saisissent pas dans Entrées : la page donne seulement le **total de chaque caissier** (le détail est dans Paiements).',
                '- **Entrées banque** = argent reçu sur un compte de l’établissement (versement de la caisse, subvention, don, recette d’activité, virement de parent…). La **banque** tient la place du bénéficiaire ; mêmes banques que les dépenses.',
                '- Un **versement de la caisse à la banque** est un **transfert** : il est montré mais **pas ajouté** au total des entrées (déjà compté en caisse).',
                '- Bilan financier : solde de caisse après versements = encaissements − dépenses caisse − versements ; solde banque = entrées banque − dépenses banque ; résultat = entrées (hors versements) − toutes les dépenses.',
                '- Droits : « Enregistrer les entrées » (on ne voit que les siennes), « Voir les entrées » (tout, caisse comprise, point des entrées), « Modifier / supprimer une entrée » (motif obligatoire, historisé).',
            ],
            'fiches' => ['entrees', 'bilans'], 'voir' => ['depenses-caisse-banque'],
        ],
        'depenses-caisse-banque' => [
            'domaine' => 'finances', 'portee' => 'application',
            'titre' => 'Dépenses caisse et dépenses banque, solde de caisse',
            'mots' => 'depense depenses caisse banque retrait bancaire solde caisse sortie tresorerie salaires',
            'texte' => [
                '- **Dépense caisse** : sortie d’argent de la caisse (salaires, fournitures, réparations…).',
                '- **Dépense banque** : payée par la banque ; on indique la **banque** (Paramètres › Banques) et la **personne qui a fait le retrait** (postes ayant le droit « Opérations bancaires »).',
                '**Solde de caisse = encaissements − dépenses caisse.** Les dépenses banque n’y entrent pas (l’argent n’est pas sorti de la caisse).',
                'Toute dépense a un numéro, une catégorie, un objet, un bénéficiaire, un justificatif possible ; une correction exige un motif.',
                'Le **bilan des dépenses** (PDF) liste date, source, catégorie, objet, bénéficiaire, mode, saisie par et montant, puis trois tableaux : par source, par catégorie et par personne ayant saisi.',
            ],
            'fiches' => ['depenses', 'banques'], 'voir' => ['controle-caisse'],
        ],

        // ================================================================ Administration
        'postes-droits' => [
            'domaine' => 'administration', 'portee' => 'application',
            'titre' => 'Postes, droits et postes protégés',
            'mots' => 'poste postes droit droits role roles permission protege sensible bouton manque menu absent plusieurs postes',
            'texte' => [
                'Un **poste** (Caissier, Éducateur…) est un ensemble de **droits**. Un compte peut avoir plusieurs postes ; on travaille avec **un poste à la fois**, choisi à la connexion.',
                'Si un bouton ou un menu manque, c’est presque toujours un droit absent du poste en cours (Administration › Rôles).',
                'Les **postes protégés** (caisse, comptabilité…) ne sont attribués que par le Super admin.',
            ],
            'fiches' => ['roles', 'utilisateurs'], 'voir' => ['portee-donnees', 'acteurs-vie-scolaire'],
        ],
        'comptes-mot-de-passe' => [
            'domaine' => 'administration', 'portee' => 'application',
            'titre' => 'Comptes et mot de passe provisoire',
            'mots' => 'compte mot de passe provisoire premiere connexion oublie reinitialiser bloque securite telephone numero identifiant email',
            'texte' => [
                'On se connecte avec son **numéro de téléphone** (ou son e-mail s’il en a un) : à la création d’un compte, le **téléphone est obligatoire**, l’e-mail facultatif.',
                'Un compte créé (ou réinitialisé) reçoit un **mot de passe provisoire**. À la première connexion, l’utilisateur doit choisir le sien (au moins 8 caractères, lettres et chiffres) avant d’accéder à l’application.',
                'Mot de passe oublié : l’administration génère un nouveau mot de passe provisoire (Administration › Utilisateurs).',
            ],
            'fiches' => ['premiere-connexion', 'utilisateurs'], 'voir' => ['postes-droits'],
        ],
        'rapports-officiels' => [
            'domaine' => 'administration', 'portee' => 'application',
            'titre' => 'Rapports officiels et rapports personnalisés',
            'mots' => 'rapport rapports rentree periode annuel officiel ministere drena memoire personnalise statistiques effectifs bilan financier global',
            'texte' => [
                '- **Rapports officiels** : rapport de **rentrée**, de **fin de période** et **annuel**, chacun en un seul document (présentation, personnel, effectifs, résultats, distinctions, assiduité, décisions), avec vos observations et perspectives, pour la hiérarchie (DRENA).',
                '- **Rapports personnalisés** : vous choisissez les colonnes (ou des colonnes libres à remplir), le regroupement (établissement, cycle, niveau, classe), les filtres et le tri.',
                '- Une vingtaine de rapports pédagogiques et financiers prêts à imprimer, dont le **Bilan financier global** : un document qui résume toutes les opérations financières de l’année (frais dus, réductions, encaissé, reste, dettes, encaissements par mode et par caissier, recettes et dépenses par mois, dépenses par catégorie et par source, solde de caisse).',
                'Menu : **Bilans › Rapports**.',
            ],
            'fiches' => ['rapports', 'rapport-personnalise'], 'voir' => ['entete-documents'],
        ],
        'entete-documents' => [
            'domaine' => 'administration', 'portee' => 'etablissement',
            'titre' => 'En-tête officiel des documents',
            'mots' => 'entete en tete document documents pdf ministere direction regionale drena logo adresse telephone',
            'texte' => [
                'Tous les PDF portent à gauche le ministère, la direction régionale, l’adresse postale et le téléphone, à droite le logo, le nom de l’école et l’année scolaire.',
                'Ces informations se remplissent dans Paramètres › Établissement › « En-tête des documents ».',
                'En bas de chaque page : le nom de l’école et l’année scolaire à gauche, « Page n / N » à droite.',
            ],
            'fiches' => ['parametres-etablissement'], 'voir' => ['rapports-officiels'],
        ],

        'billet-entree' => [
            'domaine' => 'finances', 'portee' => 'application',
            'titre' => 'Billet d’entrée en classe (billet d’autorisation)',
            'mots' => 'billet billets entree entrer autorisation autorise rentrer classe ticket validite valable echeance date fin jusqu passage caisse obtenir avoir imprimer',
            'texte' => [
                'Le **billet d’autorisation d’entrée en classe** prouve que l’élève est passé à la caisse. Il est imprimé **automatiquement en page 2 de chaque reçu** de paiement des frais (pas pour le règlement d’une dette seule).',
                '**Pour l’obtenir** : encaisser le paiement (Finances › Encaisser) puis « Imprimer le reçu » ; pour un ancien paiement, Finances › Paiements, icône « Imprimer le reçu » de la ligne (ou fiche du paiement).',
                'Il indique le nom, les prénoms, le matricule, AFF / NAFF, la classe, la date et l’heure du passage à la caisse, l’année scolaire, la photo de l’élève, la ville et la date d’édition, « LE COMPTABLE ».',
                '**Validité** :',
                '- élève **soldé** : « Valable jusqu’en fin d’année » ;',
                '- élève **pas encore soldé** : valable jusqu’à la **date d’échéance** réglée dans Paramètres › Paramètres paiements › « Reçu et billet » ;',
                '- s’il paie **à moins de 15 jours** de cette date (ou après, ou si aucune date n’est réglée) : valable le **nombre de jours** réglé au même endroit, à partir du jour du paiement ;',
                '- une date de validité saisie à la caisse lors du paiement l’emporte.',
                'Exemple : échéance 11/11, 27 jours. Paiement le 02/10 → valable jusqu’au 11/11. Paiement le 01/11 (à 10 jours de l’échéance) → valable jusqu’au 28/11.',
            ],
            'fiches' => ['billet-entree', 'encaisser'], 'voir' => ['recu-sms', 'statuts-inscription'],
        ],
        'niveaux-series' => [
            'domaine' => 'scolarite', 'portee' => 'application',
            'titre' => 'Niveau et niveau général (séries du lycée)',
            'mots' => 'niveau niveaux niveau general serie series 2ndea 2ndec 1erea 1ered tlea1 tlea2 tled seconde premiere terminale tarif educateur',
            'texte' => [
                'Comme dans la V1, il y a deux notions :',
                '- le **niveau** : avec la série au lycée (2ndeA, 2ndeC, 1ereA, 1ereD, TleA1, TleA2, TleD) ; au collège, le niveau est son propre niveau général (6eme…3eme) ;',
                '- le **niveau général** : 2nde, 1ere, Tle (et 6eme…3eme).',
                'Le **niveau** sert aux classes (TleA2³), aux inscriptions, aux coefficients des matières, aux limites d’effectif, aux listes déroulantes et aux documents (bilans par niveau).',
                'Le **niveau général** sert aux **tarifs** (une grille Tle pour toutes les séries), aux **niveaux suivis par un éducateur** (un éducateur de Tle suit TleA1, TleA2, TleD) et à l’**inscription en ligne** de l’État (qui donne 2nde, 1ere, Tle).',
            ],
            'fiches' => ['classes', 'parametres-paiements', 'utilisateurs'], 'voir' => ['cycles-application', 'classes-effectifs'],
        ],
        'mises-a-jour-en-ligne' => [
            'domaine' => 'scolarite', 'portee' => 'application',
            'titre' => 'Mise à jour depuis l’inscription en ligne (site de l’État)',
            'mots' => 'mise a jour mettre a jour en ligne scan scanner introuvable introuvables site etat sigfne journal modifier supprimer paiements niveau statut affecte',
            'texte' => [
                'Le bouton **« Mettre à jour »** de la liste des inscriptions interroge le site de l’État pour chaque élève pas encore inscrit en ligne. Il continue **en arrière-plan** si vous changez de page (indicateur en haut de l’écran).',
                'Ce qui est repris :',
                '- identité, contacts, provenance : **toujours** appliqués ;',
                '- élève **en attente de choix de classe** : niveau et statut (affecté) appliqués directement ;',
                '- élève **en attente du 1er versement** : le statut est appliqué (frais recalculés) mais un **changement de niveau reste en attente** ;',
                '- élève qui a **déjà payé** : niveau et statut restent **en attente**.',
                'Tout est noté dans **Scolarité › Mises à jour en ligne** (10 lignes par page, nombre réglable). Un élément en attente a un bouton : **« Modifier »** (éducateur, élève en attente du 1er versement) ou **« Supprimer les paiements »** (caissier, élève qui a payé, avec un motif). Les autres postes consultent seulement.',
                'Un élève **introuvable en ligne** est marqué : il apparaît en priorité (onglet « Introuvables ») et il est **rescanné** à chaque mise à jour.',
            ],
            'fiches' => ['mises-a-jour-en-ligne', 'inscription'], 'voir' => ['inscription-en-ligne', 'statuts-inscription'],
        ],
        'professeurs-classe' => [
            'domaine' => 'evaluations', 'portee' => 'application',
            'titre' => 'Professeurs d’une classe et professeur principal',
            'mots' => 'professeur professeurs principal pp classe matiere enseigne affecter designer francais orthographe composition expression orale',
            'texte' => [
                'Dans la liste d’une classe, le bloc **« Professeurs de la classe »** permet de choisir le **professeur principal** et le professeur de chaque matière.',
                'Pour une matière, la liste ne propose que les professeurs qui **enseignent cette matière** (matières données au compte dans Administration › Utilisateurs).',
                '**Orthographe, composition française et expression orale** sont des composantes du français : on y choisit les **professeurs de français**.',
                'Un professeur ne saisit que les notes des matières qui lui sont confiées.',
            ],
            'fiches' => ['professeurs-classe', 'liste-classe'], 'voir' => ['portee-donnees', 'notes-baremes'],
        ],
        'documents-impression' => [
            'domaine' => 'administration', 'portee' => 'application',
            'titre' => 'Documents PDF, impression et téléchargement',
            'mots' => 'pdf document documents imprimer impression visionneuse apercu telecharger word excel telephone portable page zoom',
            'texte' => [
                'Chaque bouton « Imprimer » ouvre la **visionneuse** : aperçu du PDF, téléchargement en **Word**, **Excel** ou **PDF**, bouton **« Imprimer »** qui lance directement l’impression, et « Terminer » pour fermer.',
                'Sur **téléphone**, les pages sont affichées par l’application ; « Imprimer » ouvre le PDF pour l’imprimer ou le partager depuis le téléphone.',
                'Les listes sont en A4 portrait, les grands tableaux en paysage ; le nom et les prénoms tiennent sur une ligne (derniers prénoms en initiales si besoin). Les très grosses listes (tout l’établissement) peuvent demander quelques secondes.',
            ],
            'fiches' => ['liste-classe', 'reste-a-payer'], 'voir' => ['entete-documents'],
        ],
        'menus-application' => [
            'domaine' => 'administration', 'portee' => 'application',
            'titre' => 'Où trouver les écrans (menus, caisse, rapports)',
            'mots' => 'menu menus ou trouver ecran page finances scolarite administration bilans point paiements caisse ancien caisse disparu rapports parametres entrees',
            'texte' => [
                '- **Scolarité** : Élèves, Classes, Inscriptions, Mises à jour en ligne, Évaluations, Absences.',
                '- **Finances** : Encaisser, Paiements, Reste à payer, Dettes, Réductions, Dépenses, **Entrées** (caisse = paiements par caissier ; banque = entrées saisies).',
                '- **Bilans** : Point inscriptions, **Point paiements** (ancien « Caisse »), Point dettes, Point réductions, Point dépenses, Point entrées, Bilan financier général, **Rapports**.',
                '- **Administration** : Utilisateurs, Rôles, Paramètres.',
                'Un menu absent = un droit absent du poste en cours (Administration › Rôles).',
            ],
            'fiches' => ['roles'], 'voir' => ['postes-droits'],
        ],
        // ================================================================ Bonnes pratiques
        'debut-annee' => [
            'domaine' => 'pratiques', 'portee' => 'conseil',
            'titre' => 'Préparer une nouvelle année scolaire (dans l’ordre)',
            'mots' => 'debut annee rentree preparer nouvelle annee ordre etapes checklist ouverture configurer',
            'texte' => [
                '1. **Années scolaires** : ouvrir l’année et ses périodes (trimestres ou semestres).',
                '2. **Paiements** : types de frais, grilles par niveau et statut (affecté / non affecté), minimum d’inscription ; puis l’**ordre de paiement**.',
                '3. **Classes** : numérotation, création des classes, effectifs maximums, professeurs principaux.',
                '4. **Matières et coefficients** par niveau.',
                '5. **Comptes** : postes, niveaux des éducateurs, matières et classes des professeurs.',
                '6. **Établissement** : en-tête des documents ; **Banques** si vous avez des dépenses banque ; SMS.',
                'Ensuite seulement : inscriptions puis encaissements.',
            ],
            'fiches' => ['annees', 'parametres-paiements', 'ordre-paiement', 'classes', 'matieres', 'utilisateurs'], 'voir' => ['grilles-tarifaires'],
        ],
        'fin-periode' => [
            'domaine' => 'pratiques', 'portee' => 'conseil',
            'titre' => 'Fin de trimestre ou de semestre',
            'mots' => 'fin periode fin trimestre conseil classe bulletins etapes avant bulletin',
            'texte' => [
                '1. Vérifier l’état de la saisie des notes par classe (je peux le consulter).',
                '2. Faire saisir les notes de conduite par les éducateurs.',
                '3. **Arrêter** les notes puis **clôturer** la période.',
                '4. Imprimer résultats et bulletins, puis le rapport de fin de période.',
            ],
            'fiches' => ['notes', 'arret-notes', 'resultats', 'rapports'], 'voir' => ['arret-cloture', 'bulletins'],
        ],
        'fin-annee' => [
            'domaine' => 'pratiques', 'portee' => 'conseil',
            'titre' => 'Fin d’année scolaire',
            'mots' => 'fin annee cloture annee decisions passage rapport annuel recouvrement dettes',
            'texte' => [
                '1. Clôturer la dernière période et imprimer les bulletins annuels.',
                '2. Préparer les décisions (admis, redouble, exclu) d’après la moyenne annuelle.',
                '3. Relancer les familles qui doivent encore (Reste à payer) : le reste deviendra une dette à la réinscription.',
                '4. Imprimer le rapport annuel.',
            ],
            'fiches' => ['resultats', 'reste-a-payer', 'rapports'], 'voir' => ['moyenne-annuelle', 'decisions-fin-annee', 'dettes'],
        ],
        'controle-caisse' => [
            'domaine' => 'pratiques', 'portee' => 'conseil',
            'titre' => 'Contrôler la caisse en fin de journée',
            'mots' => 'controle caisse fin journee arrete caisse verifier especes compter ecart',
            'texte' => [
                '1. Bilans › Point paiements sur « Aujourd’hui » : total encaissé du jour (par caissier si besoin).',
                '2. Comparer avec les espèces et les reçus (Liste des paiements, filtrée par mode).',
                '3. Déduire les dépenses caisse du jour (Finances › Dépenses).',
                '4. Corriger une erreur avec un motif plutôt que de compenser par un autre paiement.',
            ],
            'fiches' => ['point-caisse', 'depenses', 'modifier-paiement'], 'voir' => ['depenses-caisse-banque', 'correction-paiement'],
        ],
    ];

    /** Sommaire pour le prompt : id, titre, portee. */
    public static function sommaire(): string
    {
        return collect(self::THEMES)->groupBy(fn ($t) => $t['domaine'], true)
            ->map(fn ($themes, $domaine) => self::DOMAINES[$domaine].' : '.collect($themes)->map(fn ($t, $id) => "{$id} ({$t['titre']})")->implode(' ; '))
            ->implode("\n");
    }

    /** Un theme complet (outil consulter_savoir). */
    public static function theme(string $id): ?array
    {
        $t = self::THEMES[$id] ?? null;
        if (! $t) {
            return null;
        }

        return [
            'id' => $id,
            'titre' => $t['titre'],
            'domaine' => self::DOMAINES[$t['domaine']],
            'portee' => match ($t['portee']) {
                'national' => 'usage du système éducatif ivoirien (peut varier selon les établissements)',
                'etablissement' => 'règle par défaut, réglable par l’établissement (vérifier ses réglages réels)',
                'conseil' => 'bonne pratique conseillée',
                default => 'règle appliquée par Monde Éducatif',
            },
            'texte' => implode("\n", $t['texte']),
            'fiches_liees' => collect($t['fiches'])->filter(fn ($f) => isset(Guide::FICHES[$f]))->map(fn ($f) => Guide::FICHES[$f]['titre'].' ('.$f.')')->values()->all(),
            'voir_aussi' => collect($t['voir'])->filter(fn ($v) => isset(self::THEMES[$v]))->map(fn ($v) => self::THEMES[$v]['titre'].' ('.$v.')')->values()->all(),
        ];
    }

    /**
     * Themes les plus proches d'une question : [id => score], meilleur
     * d'abord. Les mots sont compares sur leur racine (4 premieres lettres
     * au moins) pour accepter pluriels et variantes (affecte / affectes).
     *
     * @return array<string, int>
     */
    public static function chercher(string $question, int $nombre = 3): array
    {
        return self::classer($question, collect(self::THEMES)->map(fn ($t, $id) => [$t['titre'], $t['mots'].' '.$t['titre'].' '.implode(' ', $t['texte']), str_replace('-', ' ', $id)]), $nombre);
    }

    /** Fiches du Guide les plus proches, avec la meme ponderation (comparables aux themes). */
    public static function chercherFiches(string $question, int $nombre = 3): array
    {
        return self::classer($question, collect(Guide::FICHES)->map(fn ($f) => [$f['titre'], $f['mots'].' '.$f['titre'].' '.$f['menu']]), $nombre);
    }

    /**
     * Score de chaque document [titre, corps] : chaque mot de la question
     * compte selon sa rarete (un mot present partout, comme « moyenne »,
     * pese peu ; un mot rare, comme « conduite », pese beaucoup), davantage
     * s'il est dans le titre, et encore s'il designe le theme lui-meme (id).
     *
     * @param  \Illuminate\Support\Collection<string, array{0: string, 1: string, 2?: string}>  $documents  [titre, corps, id]
     * @return array<string, int>
     */
    private static function classer(string $question, $documents, int $nombre): array
    {
        $mots = array_values(array_unique(self::mots($question)));
        if (! $mots) {
            return [];
        }
        static $corpus = null;
        $corpus ??= collect(self::THEMES)->map(fn ($t) => self::mots($t['mots'].' '.$t['titre']))->values()
            ->merge(collect(Guide::FICHES)->map(fn ($f) => self::mots($f['mots'].' '.$f['titre'].' '.$f['menu']))->values())->all();
        $n = count($corpus);
        $idf = [];
        foreach ($mots as $m) {
            $df = count(array_filter($corpus, fn ($doc) => self::contient($doc, $m)));
            $idf[$m] = log(1 + $n / (1 + $df));
        }

        return $documents->map(function ($d) use ($mots, $idf) {
            $cle = self::mots($d[2] ?? '');
            $titre = self::mots($d[0]);
            $corps = self::mots($d[1]);

            // Bonus : le mot designe le theme lui-meme (« dette » -> theme dettes).
            return (int) round(10 * collect($mots)->sum(fn ($m) => (self::contient($titre, $m) ? 1.5 * $idf[$m] : (self::contient($corps, $m) ? $idf[$m] : 0))
                + ($cle && self::contient($cle, $m) ? $idf[$m] : 0)));
        })->filter(fn ($s) => $s > 0)->sortDesc()->take($nombre)->all();
    }

    /** Question de comprehension (definition, calcul, raison, regle) plutot que de procedure. */
    public static function estQuestionDeSavoir(string $question): bool
    {
        $q = preg_replace('/[^a-z0-9]+/', ' ', Str::of($question)->ascii()->lower()->toString());

        return (bool) preg_match('/\b(pourquoi|c est quoi|qu est ce|que veut dire|veut dire|signifie|definition|difference|calcul\w*|combien de|regle\w*|seuil\w*|formule|a partir de|a quoi sert|compte (t il |elle )?dans|comment marche|comment fonctionne|explique\w*|est ce que|c est quand|role)\b/', $q);
    }

    /** @return list<string> */
    public static function mots(string $texte): array
    {
        $vides = ['les', 'des', 'une', 'comment', 'pour', 'est', 'que', 'qui', 'dans', 'avec', 'mon', 'mes', 'faire', 'peux', 'pas', 'quoi', 'quel', 'quelle',
            'quels', 'quelles', 'sont', 'mais', 'aux', 'par', 'sur', 'son', 'ses', 'leur', 'cette', 'ces', 'elle', 'ils', 'nous', 'vous', 'pourquoi', 'veut', 'dire'];

        return collect(preg_split('/[^a-z0-9]+/', Str::of($texte)->ascii()->lower()->toString()))
            ->filter(fn ($m) => strlen($m) > 2 && ! in_array($m, $vides, true))->values()->all();
    }

    /** Un mot de la liste a-t-il la meme racine que $mot ? */
    private static function contient(array $liste, string $mot): bool
    {
        $racine = substr($mot, 0, max(4, min(strlen($mot), 6)));
        foreach ($liste as $l) {
            if ($l === $mot || (strlen($racine) >= 4 && str_starts_with($l, $racine)) || (strlen($l) >= 4 && str_starts_with($mot, $l))) {
                return true;
            }
        }

        return false;
    }
}
