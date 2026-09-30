<?php

namespace App\Support\Assistant;

use Illuminate\Support\Str;

/**
 * Guide de l'application pour l'assistant : une fiche par tache (menu,
 * lien, droit necessaire, etapes, conseils) et, quand elle existe, la video
 * de demonstration (front : assets/demos/{demo}.webm).
 */
class Guide
{
    /** Videos de demonstration : id => [titre, duree approximative]. */
    public const DEMOS = [
        'encaisser' => ['Encaisser un paiement', '0:26'],
        'modifier-paiement' => ['Modifier ou supprimer un paiement', '0:32'],
        'inscription' => ['Inscrire un élève', '0:37'],
        'liste-classe' => ['Imprimer les listes de classe', '0:29'],
        'notes' => ['Saisir les notes d\'une évaluation', '0:32'],
        'reduction' => ['Accorder une réduction', '0:29'],
        'rapport-personnalise' => ['Créer un rapport personnalisé', '0:33'],
        'reste-a-payer' => ['Suivre le reste à payer', '0:22'],
    ];

    public const FICHES = [
        'encaisser' => [
            'titre' => 'Encaisser un paiement',
            'menu' => 'Finances › Encaisser',
            'route' => '/tabs/paiements/encaisser',
            'droit' => 'reglements.encaisser',
            'mots' => 'encaisser paiement payer versement caisse recu scolarite frais argent verser',
            'etapes' => [
                'Ouvrez Finances › Encaisser.',
                'Saisissez le matricule de l\'élève puis cliquez sur « Continuer » : la situation de l\'élève s\'affiche (frais dus, déjà payé, reste, dettes éventuelles).',
                'Saisissez le montant versé (ou cliquez sur « Solder » pour tout payer), vérifiez la date du paiement et le mode (espèces, mobile money, chèque, virement).',
                'Cliquez sur « Encaisser » : le montant est réparti automatiquement sur les frais selon l\'ordre de paiement (Paramètres › Ordre de paiement).',
                'Cliquez sur « Imprimer le reçu » pour imprimer le reçu en deux exemplaires (parent et établissement).',
            ],
            'conseils' => [
                'Le premier versement doit atteindre le minimum d\'inscription du niveau ; les suivants au moins le versement minimum (Paramètres › Paiements).',
                'Une dette d\'une année précédente se règle dans le champ « Montant pour la dette ».',
            ],
            'demo' => 'encaisser',
        ],
        'modifier-paiement' => [
            'titre' => 'Modifier ou supprimer un paiement',
            'menu' => 'Finances › Paiements',
            'route' => '/tabs/paiements/liste',
            'droit' => 'reglements.modifier',
            'mots' => 'modifier supprimer annuler corriger paiement erreur motif recu faux montant',
            'etapes' => [
                'Ouvrez Finances › Paiements et retrouvez le paiement (recherche par matricule avec la loupe, ou période).',
                'Sur la ligne du paiement, cliquez sur l\'icône crayon (modifier) ou poubelle (supprimer). Les mêmes boutons sont sur la fiche du paiement.',
                'Une fenêtre demande d\'abord le motif (obligatoire) : saisissez-le puis « Continuer ».',
                'Pour une modification : corrigez le montant, la date ou le mode de paiement, puis « Enregistrer ».',
                'Pour une suppression : après le motif, le paiement est supprimé et les montants dus sont rétablis.',
            ],
            'conseils' => [
                'Ces boutons n\'apparaissent qu\'avec le droit « Modifier / supprimer un paiement » (poste Caissier par défaut ; se règle dans Administration › Rôles).',
                'Chaque modification ou suppression est historisée avec son motif (historique des corrections sur la fiche du paiement).',
            ],
            'demo' => 'modifier-paiement',
        ],
        'inscription' => [
            'titre' => 'Inscrire un élève',
            'menu' => 'Scolarité › Inscriptions',
            'route' => '/tabs/inscriptions/nouvelle',
            'droit' => 'inscriptions.gerer',
            'mots' => 'inscrire inscription nouvel eleve reinscription matricule affecte non affecte preinscription',
            'etapes' => [
                'Ouvrez Scolarité › Inscriptions puis cliquez sur « Nouvelle inscription ».',
                'Étape 1 « Matricule » : saisissez le matricule et cliquez sur « Rechercher » (un élève déjà connu est repris, l\'inscription en ligne de l\'État est consultée), puis « Continuer ».',
                'Étape 2 « Fiche élève » : nom, prénoms, date et lieu de naissance, sexe, parents (au moins un contact), puis « Continuer ».',
                'Étape 3 « Scolarité » : niveau, statut (affecté ou non affecté), redoublant ou non ; étape 4 « Récapitulatif » : vérifiez puis enregistrez.',
                'L\'élève est inscrit et ses frais sont calculés. Placez-le ensuite dans une classe depuis sa fiche d\'inscription (« Placer dans une classe »).',
            ],
            'conseils' => [
                'Un éducateur ne voit et n\'inscrit que les élèves de ses niveaux.',
                'Le premier paiement se fait dans Finances › Encaisser.',
            ],
            'demo' => 'inscription',
        ],
        'classes' => [
            'titre' => 'Créer et gérer les classes',
            'menu' => 'Scolarité › Classes',
            'route' => '/tabs/classes',
            'droit' => 'classes.gerer',
            'mots' => 'classe creer plusieurs classes professeur principal educateur salle effectif maximum supprimer',
            'etapes' => [
                'Ouvrez Scolarité › Classes.',
                'Cliquez sur « Nouvelle classe » (ou « Créer plusieurs classes » pour un niveau entier) : niveau, nom proposé automatiquement, salle, LV2, professeur principal, éducateur.',
                'Pour modifier une classe, utilisez l\'icône crayon de sa ligne ; une classe vide peut être supprimée (icône poubelle).',
            ],
            'conseils' => ['La numérotation (chiffres ou lettres) et l\'effectif maximum se règlent dans Paramètres › Paramètres classes.'],
            'demo' => null,
        ],
        'liste-classe' => [
            'titre' => 'Consulter et imprimer les listes de classe',
            'menu' => 'Scolarité › Classes',
            'route' => '/tabs/classes',
            'droit' => 'classes.voir',
            'mots' => 'liste de classe imprimer eleves effectif filles garcons affectes redoublants toutes les classes',
            'etapes' => [
                'Ouvrez Scolarité › Classes.',
                'Pour une classe : cliquez sur l\'icône imprimante de sa ligne. Pour toutes les classes affichées (filtre de niveau compris) : bouton « Imprimer toutes les classes ».',
                'Pour voir les élèves : cliquez sur la ligne de la classe ; le bouton « Imprimer » au-dessus du tableau imprime la liste (filtrée par les compteurs filles, garçons, affectés… si vous en avez choisi un).',
            ],
            'conseils' => ['Chaque liste imprimée donne l\'effectif, les filles, les garçons, les affectés, les non affectés, les redoublants, le professeur principal et l\'éducateur.'],
            'demo' => 'liste-classe',
        ],
        'notes' => [
            'titre' => 'Saisir les notes',
            'menu' => 'Scolarité › Évaluations',
            'route' => '/tabs/evaluations',
            'droit' => 'notes.saisir',
            'mots' => 'note notes evaluation devoir interrogation saisir bareme moyenne matiere professeur',
            'etapes' => [
                'Ouvrez Scolarité › Évaluations et choisissez la période, la classe et la matière.',
                'Cliquez sur « Nouvelle évaluation », indiquez la date et le barème (sur 10, 20 ou 40).',
                'Saisissez la note de chaque élève (Entrée passe à l\'élève suivant ; laissez vide pour un absent), puis « Enregistrer ».',
                'Les moyennes et les rangs se calculent tout seuls ; le crayon et la poubelle de l\'en-tête modifient ou suppriment une évaluation.',
            ],
            'conseils' => [
                'Un professeur ne saisit que les matières de ses classes (affectations faites dans la liste de classe, « Professeurs de la classe »).',
                'Quand le directeur a arrêté les notes (Paramètres › Arrêt des notes), la saisie est bloquée.',
            ],
            'demo' => 'notes',
        ],
        'arret-notes' => [
            'titre' => 'Arrêter les notes et clôturer une période',
            'menu' => 'Administration › Paramètres › Arrêt des notes',
            'route' => '/tabs/parametres/arret-notes',
            'droit' => 'notes.arreter',
            'mots' => 'arreter arret notes cloturer cloture trimestre semestre fin de periode bloquer rouvrir',
            'etapes' => [
                'Ouvrez Paramètres › Arrêt des notes et choisissez la période.',
                'Arrêtez les notes d\'une classe (bouton de sa ligne) ou de toutes les classes (« Arrêter toutes les notes »).',
                'Pour terminer le trimestre ou le semestre : « Mettre fin au … » arrête tout et bloque la saisie des notes, absences et conduite.',
                'En cas d\'erreur : « Rouvrir » sur la classe ou « Rouvrir la période ».',
            ],
            'conseils' => ['Réservé au directeur des études (droit « Arrêter les notes »).'],
            'demo' => null,
        ],
        'resultats' => [
            'titre' => 'Résultats, rangs et bulletins',
            'menu' => 'Scolarité › Évaluations › Résultats',
            'route' => '/tabs/evaluations/resultats',
            'droit' => 'bulletins.voir',
            'mots' => 'resultats bulletin bulletins rang moyenne generale decision admis redouble imprimer matrice conseil de classe',
            'etapes' => [
                'Ouvrez Évaluations puis l\'onglet Résultats ; choisissez la classe et la période (ou « Annuelle »).',
                'Le tableau donne les moyennes par matière, la moyenne générale, le rang et les distinctions.',
                'Imprimez les bulletins de toute la classe ou ceux d\'un élève (icône de sa ligne).',
                'En fin d\'année (période « Annuelle »), proposez puis enregistrez les décisions (admis, redouble, exclu).',
            ],
            'conseils' => ['Une matière encore « provisoire » n\'est pas arrêtée : ses notes peuvent encore changer.'],
            'demo' => null,
        ],
        'absences' => [
            'titre' => 'Saisir les absences et la conduite',
            'menu' => 'Scolarité › Absences',
            'route' => '/tabs/absences',
            'droit' => 'absences.gerer',
            'mots' => 'absence absences retard appel conduite justifier justifiee heures eleve absent',
            'etapes' => [
                'Ouvrez Scolarité › Absences, choisissez la classe et cliquez sur « Faire l\'appel ».',
                'Cochez les élèves absents, indiquez la date, le nombre d\'heures et, si besoin, le motif (absence justifiée ou non).',
                'L\'onglet « Conduite » sert à saisir la note de conduite de la période.',
            ],
            'conseils' => ['Les heures non justifiées retirent des points de conduite.'],
            'demo' => null,
        ],
        'reste-a-payer' => [
            'titre' => 'Suivre le reste à payer',
            'menu' => 'Finances › Reste à payer',
            'route' => '/tabs/paiements/reste-a-payer',
            'droit' => 'reglements.voir',
            'mots' => 'reste a payer impayes relance recouvrement solde eleves qui doivent',
            'etapes' => [
                'Ouvrez Finances › Reste à payer : chaque élève inscrit avec son montant à payer, ce qui reste sur l\'inscription et sur ses dettes.',
                'Les compteurs du haut filtrent par statut (attente du 1er versement, attente de solder, soldés) ; « Filtres » choisit le niveau, la classe, le sexe…',
                'Cliquez sur une ligne pour ouvrir la fiche d\'inscription de l\'élève (frais, paiements, encaisser).',
                'Imprimez la liste ou l\'impression générale (statistiques).',
            ],
            'conseils' => [],
            'demo' => 'reste-a-payer',
        ],
        'dettes' => [
            'titre' => 'Dettes des années précédentes',
            'menu' => 'Finances › Dettes et Point dettes',
            'route' => '/tabs/paiements/dettes',
            'droit' => 'dettes.gerer',
            'mots' => 'dette dettes annee precedente ancienne point dettes annuler dette',
            'etapes' => [
                'Finances › Dettes : la liste des élèves qui ont une dette, avec le montant, les réductions, le payé et le reste.',
                'Finances › Point dettes : les dettes encaissées sur une période (filtres : période, élèves, caissier) ; l\'impression générale ajoute la situation à ce jour et le détail par caissier, niveau et classe.',
                'Une dette se règle à l\'encaissement (champ « Montant pour la dette »).',
            ],
            'conseils' => ['Une dette peut être réduite ou annulée dans Finances › Réductions (« Réduction dette »).'],
            'demo' => null,
        ],
        'reduction' => [
            'titre' => 'Accorder une réduction ou un cas social',
            'menu' => 'Finances › Réductions',
            'route' => '/tabs/reductions',
            'droit' => 'reductions.gerer',
            'mots' => 'reduction remise cas social bourse rabais annuler dette donneur d ordre',
            'etapes' => [
                'Ouvrez Finances › Réductions et cliquez sur « Réduction inscription » (ou « Réduction dette »).',
                'Saisissez le matricule de l\'élève puis validez.',
                'Choisissez le type de réduction : ses limites s\'affichent avec l\'aperçu de la baisse sur chaque frais.',
                'Saisissez le montant et « Accordée sur ordre de » (obligatoire), puis enregistrez.',
            ],
            'conseils' => [
                'Supprimer une réduction (poubelle de sa ligne) rétablit les montants dus.',
                'Le type « Cas social » compte dans la ligne « Cas sociaux » du point des inscriptions.',
            ],
            'demo' => 'reduction',
        ],
        'point-caisse' => [
            'titre' => 'Point de caisse',
            'menu' => 'Finances › Caisse',
            'route' => '/tabs/paiements',
            'droit' => 'reglements.voir',
            'mots' => 'point de caisse bilan journee recette encaisse total caissier imprimer point',
            'etapes' => [
                'Ouvrez Finances › Caisse : le point des encaissements, affectés / non affectés.',
                '« Filtres » : période (jour, dates, mois, année) et critères d\'élèves ; puis « Imprimer le point » ou « Impression générale ».',
            ],
            'conseils' => ['Le point des dépenses (encaissements moins dépenses) est dans Finances › Dépenses › Point de caisse.'],
            'demo' => null,
        ],
        'point-inscriptions' => [
            'titre' => 'Point des inscriptions',
            'menu' => 'Scolarité › Point inscriptions',
            'route' => '/tabs/inscriptions/point',
            'droit' => 'inscriptions.gerer',
            'mots' => 'point des inscriptions inscrits passes a la caisse pas passes cas sociaux liste des inscrits',
            'etapes' => [
                'Ouvrez Scolarité › Point inscriptions : passés à la caisse, cas sociaux et pas passés à la caisse, affectés et non affectés.',
                '« Filtres » choisit la période (date d\'inscription) et les élèves ; « Liste des inscrits » et l\'œil de chaque case ouvrent la liste des élèves.',
                '« Imprimer le point » ou « Impression générale » (détail par genre, niveau, classe et agent).',
            ],
            'conseils' => [],
            'demo' => null,
        ],
        'depenses' => [
            'titre' => 'Enregistrer une dépense',
            'menu' => 'Finances › Dépenses',
            'route' => '/tabs/depenses',
            'droit' => 'depenses.gerer',
            'mots' => 'depense depenses sortie de caisse achat justificatif fournisseur',
            'etapes' => [
                'Ouvrez Finances › Dépenses et cliquez sur « Nouvelle dépense ».',
                'Saisissez la date, le montant, l\'objet de la dépense, le bénéficiaire et joignez la pièce comptable si vous l\'avez, puis enregistrez.',
            ],
            'conseils' => ['Sans le droit « Voir les dépenses », on ne voit que ses propres dépenses.'],
            'demo' => null,
        ],
        'rapports' => [
            'titre' => 'Rapports (rentrée, période, annuel)',
            'menu' => 'Finances › Rapports',
            'route' => '/tabs/rapports',
            'droit' => 'rapports.voir',
            'mots' => 'rapport rapports rentree trimestre semestre annuel fin d annee ministere drena statistiques effectifs resultats',
            'etapes' => [
                'Ouvrez Rapports.',
                '« Rapports officiels » : rapport de rentrée, de fin de période ou de fin d\'année. Rédigez vos observations, difficultés et perspectives puis « Générer le rapport » (document complet : page de garde, sommaire, chapitres, tableaux, conclusion).',
                'Les autres cartes affichent un rapport précis (effectifs, résultats, majors…) à l\'écran, imprimable.',
            ],
            'conseils' => ['Un rapport inutile peut être retiré du catalogue (icône œil barré) et remis plus tard.'],
            'demo' => null,
        ],
        'rapport-personnalise' => [
            'titre' => 'Créer un rapport personnalisé',
            'menu' => 'Finances › Rapports › Mes rapports',
            'route' => '/tabs/rapports',
            'droit' => 'rapports.voir',
            'mots' => 'rapport personnalise liste personnalisee colonnes entetes emargement signature par classe par niveau par cycle modele',
            'etapes' => [
                'Ouvrez Rapports puis « Créer un rapport » (rubrique Mes rapports).',
                'Donnez un titre et choisissez « Une liste par » : établissement, cycle, niveau ou classe.',
                'Ajoutez les colonnes (champs de l\'élève, des parents, de la scolarité, des résultats, des finances) ou des colonnes libres dont vous tapez l\'en-tête (Signature, Observation…). Renommez ou réordonnez-les.',
                'Filtrez les élèves (genre, statut, redoublants, cycles, niveaux, classes), choisissez le tri, puis « Aperçu » et « Imprimer ».',
                '« Enregistrer » garde le rapport dans Mes rapports pour le réimprimer plus tard.',
            ],
            'conseils' => ['Les moyennes demandent de choisir la période.'],
            'demo' => 'rapport-personnalise',
        ],
        'utilisateurs' => [
            'titre' => 'Créer un compte utilisateur',
            'menu' => 'Administration › Utilisateurs',
            'route' => '/tabs/utilisateurs',
            'droit' => 'utilisateurs.gerer',
            'mots' => 'utilisateur compte creer mot de passe poste reinitialiser personnel professeur caissier acces',
            'etapes' => [
                'Ouvrez Administration › Utilisateurs et créez le compte (nom, prénoms, e-mail, téléphone, poste).',
                'Un mot de passe provisoire s\'affiche : communiquez-le à la personne pour sa première connexion (bouton « Mot de passe provisoire » pour en générer un nouveau en cas d\'oubli).',
                'Selon le poste : niveaux suivis (éducateur) ou matières (professeur).',
            ],
            'conseils' => ['Les droits de chaque poste se règlent dans Administration › Rôles.'],
            'demo' => null,
        ],
        'premiere-connexion' => [
            'titre' => 'Première connexion : choisir son mot de passe',
            'menu' => 'Écran de connexion',
            'route' => '/tabs/dashboard',
            'droit' => 'aucun (tout utilisateur)',
            'mots' => 'premiere connexion mot de passe provisoire changer nouveau oublie bloque choisir mon mot de passe',
            'etapes' => [
                'Connectez-vous avec votre e-mail et le mot de passe provisoire remis par l\'administration.',
                'L\'écran « Choisissez votre mot de passe » s\'ouvre : ressaisissez le mot de passe provisoire, puis votre nouveau mot de passe deux fois (au moins 8 caractères, avec des lettres et des chiffres).',
                'Cliquez sur « Enregistrer et continuer », puis choisissez votre année scolaire, votre établissement et votre poste.',
            ],
            'conseils' => [
                'Tant que le mot de passe n\'est pas choisi, l\'application reste bloquée sur cet écran.',
                'Mot de passe oublié : l\'administration génère un nouveau mot de passe provisoire (Administration › Utilisateurs).',
            ],
            'demo' => null,
        ],
        'roles' => [
            'titre' => 'Donner ou retirer des droits à un poste',
            'menu' => 'Administration › Rôles',
            'route' => '/tabs/roles',
            'droit' => 'roles.gerer',
            'mots' => 'droit droits permission role poste acces autoriser bouton n apparait pas je ne vois pas',
            'etapes' => [
                'Ouvrez Administration › Rôles et choisissez le poste.',
                'Cochez ou décochez les droits puis enregistrez. Les personnes concernées voient les changements à leur prochaine action.',
            ],
            'conseils' => ['Si un bouton ou un menu manque, c\'est presque toujours un droit absent du poste en cours.'],
            'demo' => null,
        ],
        'parametres-etablissement' => [
            'titre' => 'Informations de l\'établissement et en-tête des documents',
            'menu' => 'Administration › Paramètres › Établissement',
            'route' => '/tabs/parametres/etablissement',
            'droit' => 'etablissement.gerer',
            'mots' => 'logo en-tete entete ministere direction regionale drena adresse telephone nom ecole documents pdf',
            'etapes' => [
                'Ouvrez Paramètres › Établissement.',
                'Renseignez le nom, le logo, les coordonnées et, dans « En-tête des documents », le ministère de tutelle, la direction régionale, l\'adresse postale et le téléphone.',
                'Enregistrez : tous les PDF (listes, reçus, bulletins, rapports) reprennent ces informations.',
            ],
            'conseils' => [],
            'demo' => null,
        ],
        'ordre-paiement' => [
            'titre' => 'Ordre de paiement des frais',
            'menu' => 'Administration › Paramètres › Ordre de paiement',
            'route' => '/tabs/parametres/ordre-paiement',
            'droit' => 'tarifs.gerer',
            'mots' => 'ordre de paiement priorite frais repartition inscription scolarite annexes premier',
            'etapes' => [
                'Ouvrez Paramètres › Ordre de paiement.',
                'Rangez les frais avec les flèches ou par glisser-déposer : un encaissement solde le premier frais, puis passe au suivant.',
                'Vérifiez avec la simulation, puis enregistrez. Le nouvel ordre vaut pour les prochains encaissements.',
            ],
            'conseils' => [],
            'demo' => null,
        ],
        'annees' => [
            'titre' => 'Années scolaires et périodes',
            'menu' => 'Administration › Paramètres › Années scolaires',
            'route' => '/tabs/parametres/annees',
            'droit' => 'annees_scolaires.gerer',
            'mots' => 'annee scolaire nouvelle annee trimestre semestre periode dates ouvrir cloturer changer d annee',
            'etapes' => [
                'Ouvrez Paramètres › Années scolaires pour créer une année, choisir le découpage (trimestres ou semestres) et les dates.',
                'Pour travailler sur une autre année ou période, utilisez le sélecteur d\'année en haut de l\'écran.',
            ],
            'conseils' => ['La fin d\'un trimestre ou d\'un semestre se fait dans Paramètres › Arrêt des notes (directeur).'],
            'demo' => null,
        ],
    ];

    /** Resume des fiches pour le prompt : id, titre, menu. */
    public static function sommaire(): string
    {
        return collect(self::FICHES)->map(fn ($f, $id) => "- {$id} : {$f['titre']} ({$f['menu']})".($f['demo'] ? ' [démo vidéo]' : ''))->implode("\n");
    }

    public static function fiche(string $id): ?array
    {
        $f = self::FICHES[$id] ?? null;

        return $f ? ['id' => $id] + $f + ['demo_titre' => $f['demo'] ? self::DEMOS[$f['demo']][0] : null] : null;
    }

    /** Fiches les plus proches d'une question (mode sans IA). */
    public static function chercher(string $question, int $nombre = 3): array
    {
        $mots = collect(preg_split('/[^a-z0-9]+/', Str::of($question)->ascii()->lower()->toString()))
            ->filter(fn ($m) => strlen($m) > 2)->reject(fn ($m) => in_array($m, ['les', 'des', 'une', 'comment', 'pour', 'est', 'que', 'qui', 'dans', 'avec', 'mon', 'mes', 'faire', 'peux', 'pas'], true));
        if ($mots->isEmpty()) {
            return [];
        }

        return collect(self::FICHES)->map(function ($f, $id) use ($mots) {
            $texte = Str::of($f['titre'].' '.$f['mots'].' '.$f['menu'])->ascii()->lower()->toString();
            $score = $mots->sum(fn ($m) => str_contains($texte, $m) ? (str_contains(Str::of($f['titre'])->ascii()->lower()->toString(), $m) ? 3 : 2) : 0);

            return ['id' => $id, 'score' => $score];
        })->where('score', '>', 0)->sortByDesc('score')->take($nombre)->pluck('id')->all();
    }
}
