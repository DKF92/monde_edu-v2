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
            'mots' => 'encaisser paiement payer versement caisse recu scolarite frais argent verser billet',
            'etapes' => [
                'Ouvrez Finances › Encaisser.',
                'Saisissez le matricule de l\'élève puis cliquez sur « Continuer » : la situation de l\'élève s\'affiche (frais dus, déjà payé, reste, dettes éventuelles).',
                'Saisissez le montant versé (ou cliquez sur « Solder » pour tout payer), vérifiez la date du paiement et le mode (espèces, mobile money, chèque, virement).',
                'Cliquez sur « Encaisser » : le montant est réparti automatiquement sur les frais selon l\'ordre de paiement (Paramètres › Ordre de paiement).',
                'Cliquez sur « Imprimer le reçu » : le reçu en deux exemplaires (parent et établissement), puis en page 2 le billet d\'autorisation d\'entrée en classe.',
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
            'mots' => 'inscrire inscription nouvel eleve reinscription matricule affecte non affecte preinscription terminer terminer inscription choisir classe placer',
            'etapes' => [
                'Ouvrez Scolarité › Inscriptions puis cliquez sur « Nouvelle inscription ».',
                'Étape 1 « Matricule » : saisissez le matricule et cliquez sur « Rechercher » (un élève déjà connu est repris, l\'inscription en ligne de l\'État est consultée), puis « Continuer ».',
                'Étape 2 « Fiche élève » : nom, prénoms, date et lieu de naissance, sexe, parents (au moins un contact), puis « Continuer ».',
                'Étape 3 « Scolarité » : niveau (avec la série au lycée : 2ndeA, TleD…), statut (affecté ou non affecté), redoublant ou non ; étape 4 « Récapitulatif » : vérifiez puis enregistrez.',
                'Sans classe, l\'inscription reste « en attente de choix de classe » : ouvrez sa fiche et cliquez sur « Terminer inscription » pour choisir la classe (ses frais sont alors fixés).',
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
                'Cliquez sur « Nouvelle classe » (ou « Créer plusieurs classes » pour un niveau entier) : niveau, numéro proposé automatiquement (la classe s\'appelle par exemple 6eme¹ ou TleA2³), salle, LV2.',
                'Le professeur principal et les professeurs des matières se choisissent dans la liste de la classe, bloc « Professeurs de la classe ».',
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
            'conseils' => [
                'Chaque liste imprimée (A4 portrait) donne l\'effectif, les filles, les garçons, les affectés, les non affectés, les redoublants, le professeur principal et l\'éducateur, puis matricule, nom et prénoms, sexe, statut, redoublant et une colonne Note / Moyenne à remplir.',
                'N\'apparaissent que les élèves qui ont atteint le statut réglé dans Paramètres › Paramètres inscriptions (1er paiement par défaut).',
            ],
            'demo' => 'liste-classe',
        ],
        'notes' => [
            'titre' => 'Saisir les notes',
            'menu' => 'Scolarité › Évaluations',
            'route' => '/tabs/evaluations',
            'droit' => 'notes.saisir',
            'mots' => 'note notes evaluation devoir interrogation saisir bareme moyenne matiere professeur educateur informaticien',
            'etapes' => [
                'Ouvrez Scolarité › Évaluations et choisissez la période, la classe et la matière.',
                'Cliquez sur « Nouvelle évaluation », indiquez la date et le barème (sur 10, 20 ou 40).',
                'Saisissez la note de chaque élève (Entrée passe à l\'élève suivant ; laissez vide pour un absent), puis « Enregistrer ».',
                'Les moyennes et les rangs se calculent tout seuls ; le crayon et la poubelle de l\'en-tête modifient ou suppriment une évaluation.',
            ],
            'conseils' => [
                'Un professeur ne saisit que les matières de ses classes (affectations faites dans la liste de classe, « Professeurs de la classe »).',
                'L\'éducateur ne saisit que la note de conduite (Scolarité › Absences, onglet « Conduite ») ; saisir les notes à la place des professeurs demande le droit « Saisir les notes pour les professeurs » (informaticien par défaut).',
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
                'Imprimez la liste (« Imprimer ») ou la liste avec statistiques (« Imprimer avec stats » : tableaux par genre, statut, redoublement, cycle, niveau et classe en tête du document).',
            ],
            'conseils' => [],
            'demo' => 'reste-a-payer',
        ],
        'dettes' => [
            'titre' => 'Dettes des années précédentes',
            'menu' => 'Finances › Dettes et Bilans › Point dettes',
            'route' => '/tabs/paiements/dettes',
            'droit' => 'dettes.gerer',
            'mots' => 'dette dettes annee precedente ancienne point dettes annuler dette',
            'etapes' => [
                'Finances › Dettes : la liste des élèves qui ont une dette, avec le montant, les réductions, le payé et le reste.',
                'Bilans › Point dettes : les dettes encaissées sur une période (filtres : période, élèves, caissier) ; l\'impression générale ajoute la situation à ce jour et le détail par caissier, niveau et classe.',
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
            'menu' => 'Bilans › Point paiements',
            'route' => '/tabs/paiements',
            'droit' => 'reglements.encaisser',
            'mots' => 'point de caisse point paiements bilan journee recette encaisse total caissier imprimer point bilan general',
            'etapes' => [
                'Ouvrez Bilans › Point paiements : le point des encaissements, affectés / non affectés.',
                '« Filtres » : période (jour, dates, mois, année) et critères d\'élèves ; puis « Imprimer le point » ou « Impression générale ».',
            ],
            'conseils' => [
                'Le bilan général (PDF) donne le bilan général puis, en point 2, un bilan par caissier.',
                'Le point des dépenses (encaissements moins dépenses) est dans Finances › Dépenses › Point de caisse.',
            ],
            'demo' => null,
        ],
        'point-inscriptions' => [
            'titre' => 'Point des inscriptions',
            'menu' => 'Bilans › Point inscriptions',
            'route' => '/tabs/inscriptions/point',
            'droit' => 'inscriptions.gerer',
            'mots' => 'point des inscriptions inscrits passes a la caisse pas passes cas sociaux liste des inscrits',
            'etapes' => [
                'Ouvrez Bilans › Point inscriptions : passés à la caisse, cas sociaux et pas passés à la caisse, affectés et non affectés.',
                '« Filtres » choisit la période (date d\'inscription) et les élèves ; « Liste des inscrits » et l\'œil de chaque case ouvrent la liste des élèves.',
                '« Imprimer le point » ou « Impression générale » (détail par genre, niveau, classe et agent).',
            ],
            'conseils' => ['La liste des inscrits imprimée donne le statut de l\'inscription de chaque élève (pas de montants).'],
            'demo' => null,
        ],
        'depenses' => [
            'titre' => 'Enregistrer une dépense',
            'menu' => 'Finances › Dépenses',
            'route' => '/tabs/depenses',
            'droit' => 'depenses.gerer',
            'mots' => 'depense depenses sortie de caisse achat justificatif fournisseur banque retrait bancaire cheque virement',
            'etapes' => [
                'Ouvrez Finances › Dépenses et cliquez sur « Nouvelle dépense ».',
                'Choisissez « Payée par » : la caisse (sortie de caisse) ou la banque. Pour la banque, choisissez la banque et la personne qui a fait le retrait.',
                'Saisissez la date, le montant, l\'objet de la dépense, le bénéficiaire et joignez la pièce comptable si vous l\'avez, puis enregistrez.',
            ],
            'conseils' => [
                'Sans le droit « Voir les dépenses », on ne voit que ses propres dépenses.',
                'Les banques s\'enregistrent dans Paramètres › Banques ; seuls les postes ayant le droit « Opérations bancaires » sont proposés pour le retrait.',
                'Le point des dépenses (par catégorie, caisse et banque) est dans Bilans › Point dépenses.',
                'Le solde de caisse ne compte que les dépenses payées par la caisse.',
            ],
            'demo' => null,
        ],
        'rapports' => [
            'titre' => 'Rapports (rentrée, période, annuel, bilan financier global)',
            'menu' => 'Bilans › Rapports',
            'route' => '/tabs/rapports',
            'droit' => 'rapports.voir',
            'mots' => 'rapport rapports rentree trimestre semestre annuel fin d annee ministere drena statistiques effectifs resultats bilan financier global finances annee',
            'etapes' => [
                'Ouvrez Rapports.',
                '« Rapports officiels » : rapport de rentrée, de fin de période ou de fin d\'année. Rédigez vos observations, difficultés et perspectives puis « Générer le rapport » (document complet : page de garde, sommaire, chapitres, tableaux, conclusion).',
                'Les autres cartes affichent un rapport précis (effectifs, résultats, majors…) à l\'écran, imprimable. « Bilan financier global » (rubrique Finances) résume toutes les opérations financières de l\'année en un document ; il est aussi à l\'écran dans Bilans › Bilan financier général.',
            ],
            'conseils' => ['Un rapport inutile peut être retiré du catalogue (icône œil barré) et remis plus tard.'],
            'demo' => null,
        ],
        'rapport-personnalise' => [
            'titre' => 'Créer un rapport personnalisé',
            'menu' => 'Bilans › Rapports › Mes rapports',
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
                'Ouvrez Administration › Utilisateurs et créez le compte : nom, prénoms, **téléphone (obligatoire, sert à se connecter)**, e-mail (facultatif), poste.',
                'Un mot de passe provisoire s\'affiche : communiquez-le à la personne pour sa première connexion (bouton « Mot de passe provisoire » pour en générer un nouveau en cas d\'oubli).',
                'Selon le poste : niveaux suivis (éducateur : niveaux généraux 6eme…Tle, il suit toutes les séries) ou matières (professeur).',
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
                'Connectez-vous avec votre numéro de téléphone (ou votre e-mail) et le mot de passe provisoire remis par l\'administration.',
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
        'parametres-paiements' => [
            'titre' => 'Fixer les tarifs (grilles), les types de frais et de réductions',
            'menu' => 'Administration › Paramètres › Paramètres paiements',
            'route' => '/tabs/parametres/paiements',
            'droit' => 'tarifs.gerer',
            'mots' => 'tarif tarifs grille grilles montant montants frais prix cout scolarite inscription annexes minimum type frais type reduction cantine transport affecte non affecte validite billet echeance',
            'etapes' => [
                'Ouvrez Paramètres › Paramètres paiements.',
                'Onglet « Montants d\'inscription » : choisissez le niveau général (une grille Tle vaut pour toutes les séries), saisissez le montant de chaque frais pour un élève affecté et pour un non affecté, puis cliquez sur « Enregistrer ». Le minimum d\'inscription se calcule tout seul (annexes, + inscription pour un non affecté).',
                'Onglet « Types de frais » : ajoutez vos frais propres (cantine, transport…), activez ou désactivez-les et choisissez les élèves concernés (affectés, non affectés).',
                'Onglet « Réductions » : réglez la réduction et le cas social (minimum, plafond).',
                'Onglet « Reçu et billet » : date d\'échéance du billet d\'entrée en classe et nombre de jours (paiement proche de l\'échéance).',
            ],
            'conseils' => [
                'Faites-le avant les inscriptions : sans grille pour un niveau, on ne peut pas y inscrire d\'élève.',
                'Les élèves déjà placés en classe gardent les montants copiés à leur inscription.',
            ],
            'demo' => null,
        ],
        'matieres' => [
            'titre' => 'Matières et coefficients',
            'menu' => 'Administration › Paramètres › Matières et coefficients',
            'route' => '/tabs/parametres/matieres',
            'droit' => 'matieres.gerer',
            'mots' => 'matiere matieres coefficient coefficients groupe lettres sciences litteraire scientifique enseignee niveau',
            'etapes' => [
                'Ouvrez Paramètres › Matières et coefficients.',
                'Choisissez le niveau (avec sa série au lycée : les coefficients diffèrent, ex. Maths TleA2 = 2, TleD = 4), puis ajoutez ou modifiez les matières enseignées et leur coefficient.',
                'Choisissez le groupe de chaque matière (lettres ou sciences) : il la place dans le bilan correspondant du bulletin.',
            ],
            'conseils' => ['La conduite est une matière à part, de coefficient 1, notée par l\'éducateur.'],
            'demo' => null,
        ],
        'parametres-inscriptions' => [
            'titre' => 'Paramètres des inscriptions (matricule, inscription en ligne, affichage en classe)',
            'menu' => 'Administration › Paramètres › Paramètres inscriptions',
            'route' => '/tabs/parametres/inscriptions',
            'droit' => 'etablissement.gerer',
            'mots' => 'format matricule verification en ligne code mena affichage classe statut visible liste classe apparait',
            'etapes' => [
                'Ouvrez Paramètres › Paramètres inscriptions.',
                '« Formats du matricule national » : indiquez les formats acceptés (9 = chiffre, A = lettre) et testez un matricule.',
                '« Inscription en ligne » : activez la consultation du reçu de l\'État et saisissez le code MENA de l\'établissement.',
                '« Affichage des élèves dans les classes » : choisissez à partir de quand un élève apparaît dans sa liste de classe (classe attribuée, 1er paiement ou soldé).',
            ],
            'conseils' => [],
            'demo' => null,
        ],
        'banques' => [
            'titre' => 'Enregistrer les banques (dépenses banque)',
            'menu' => 'Administration › Paramètres › Banques',
            'route' => '/tabs/parametres/banques',
            'droit' => 'etablissement.gerer ou depenses.modifier',
            'mots' => 'banque banques compte bancaire retrait bancaire depense banque agence',
            'etapes' => [
                'Ouvrez Paramètres › Banques et cliquez sur « Ajouter une banque ».',
                'Saisissez le nom, le sigle, le numéro de compte et l\'agence, puis enregistrez.',
                'La banque est alors proposée dans « Nouvelle dépense » quand on choisit « Payée par : la banque ».',
            ],
            'conseils' => [
                'Une banque déjà utilisée ne se supprime pas : désactivez-la (icône œil barré).',
                'Pour le retrait, seuls les postes ayant le droit « Opérations bancaires » sont proposés (Administration › Rôles).',
            ],
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
        'billet-entree' => [
            'titre' => 'Obtenir le billet d\'entrée en classe',
            'menu' => 'Finances › Encaisser (ou Finances › Paiements)',
            'route' => '/tabs/paiements/encaisser',
            'droit' => 'reglements.encaisser',
            'mots' => 'billet billets entree entrer autorisation classe ticket rentrer en classe validite valable obtenir avoir imprimer',
            'etapes' => [
                'Le billet d\'autorisation d\'entrée en classe est imprimé avec le reçu, en page 2 : il n\'y a rien à demander à part.',
                'Nouveau paiement : Finances › Encaisser, matricule, montant, « Encaisser », puis « Imprimer le reçu ».',
                'Paiement déjà fait : Finances › Paiements, retrouvez le paiement (recherche par nom, matricule ou n° de reçu) et cliquez sur l\'icône « Imprimer le reçu » de sa ligne (ou ouvrez le paiement puis « Imprimer le reçu »).',
                'Dans la visionneuse, « Imprimer » lance l\'impression : page 1 le reçu (2 exemplaires), page 2 le billet.',
            ],
            'conseils' => [
                'Élève soldé : billet valable jusqu\'en fin d\'année. Sinon : jusqu\'à la date d\'échéance réglée dans Paramètres › Paramètres paiements › « Reçu et billet », ou le nombre de jours réglé si le paiement est à moins de 15 jours de cette date.',
                'Pas de billet pour un paiement de dette seule.',
            ],
            'demo' => 'encaisser',
        ],
        'mises-a-jour-en-ligne' => [
            'titre' => 'Mettre à jour depuis l\'inscription en ligne',
            'menu' => 'Scolarité › Inscriptions (bouton « Mettre à jour ») et Scolarité › Mises à jour en ligne',
            'route' => '/tabs/inscriptions/mises-a-jour',
            'droit' => 'inscriptions.gerer (consultation : inscriptions.voir)',
            'mots' => 'mise a jour mettre a jour en ligne site etat scan introuvable introuvables modifier supprimer paiements journal',
            'etapes' => [
                'Ouvrez Scolarité › Inscriptions et cliquez sur « Mettre à jour » : chaque élève pas encore inscrit en ligne est vérifié sur le site de l\'État (cela continue si vous changez de page).',
                'Ouvrez Scolarité › Mises à jour en ligne : ce qui a été repris, ce qui reste en attente et les élèves introuvables (onglet « Introuvables », en priorité).',
                'Élément en attente : « Modifier » (éducateur, élève pas encore passé à la caisse) ouvre l\'inscription avec la valeur en ligne ; « Supprimer les paiements » (caissier, élève qui a payé) demande un motif.',
            ],
            'conseils' => ['Les introuvables sont revérifiés à chaque nouvelle mise à jour.'],
            'demo' => null,
        ],
        'professeurs-classe' => [
            'titre' => 'Choisir les professeurs et le professeur principal d\'une classe',
            'menu' => 'Scolarité › Classes › (liste de la classe) › Professeurs de la classe',
            'route' => '/tabs/classes',
            'droit' => 'classes.gerer ou matieres.gerer',
            'mots' => 'professeur professeurs principal pp classe matiere affecter designer enseignant',
            'etapes' => [
                'Ouvrez Scolarité › Classes et cliquez sur la classe.',
                'Dépliez « Professeurs de la classe ».',
                'Choisissez le professeur principal, puis le professeur de chaque matière (seuls ceux qui enseignent la matière sont proposés ; pour orthographe, composition française et expression orale : les professeurs de français).',
                'Cliquez sur « Enregistrer ».',
            ],
            'conseils' => ['Les matières d\'un professeur se donnent sur son compte (Administration › Utilisateurs).'],
            'demo' => null,
        ],
        'parametres-dettes' => [
            'titre' => 'Choisir les années de dettes prises en compte',
            'menu' => 'Administration › Paramètres › Dettes',
            'route' => '/tabs/parametres/dettes',
            'droit' => 'tarifs.gerer',
            'mots' => 'dettes annees prises en compte n-1 combien annees dette ancienne parametre',
            'etapes' => [
                'Ouvrez Paramètres › Dettes.',
                'Indiquez sur combien d\'années en arrière la dette compte (1 = seulement le reste de l\'année précédente), puis enregistrez.',
            ],
            'conseils' => ['Le réglage s\'applique à la caisse, au reste à payer, à la liste et au point des dettes et aux réductions.'],
            'demo' => null,
        ],
        'entrees' => [
            'titre' => 'Enregistrer une entrée (banque) et voir les entrées caisse',
            'menu' => 'Finances › Entrées',
            'route' => '/tabs/entrees',
            'droit' => 'entrees.gerer (tout voir : entrees.voir)',
            'mots' => 'entree entrees recette recettes banque versement verser deposer depot subvention don virement argent recu caisse caissier total',
            'etapes' => [
                'Ouvrez Finances › Entrées : en haut, les entrées caisse (paiements des élèves) avec le total de chaque caissier sur la période ; l\'œil d\'un caissier ouvre ses paiements.',
                'Pour de l\'argent reçu à la banque, cliquez sur « Nouvelle entrée banque ».',
                'Choisissez la banque qui reçoit (elle tient la place du bénéficiaire), la date, le montant, la catégorie (versement de la caisse, subvention, don…), l\'objet, la provenance, et joignez le bordereau si vous l\'avez, puis enregistrez.',
            ],
            'conseils' => [
                'Les paiements des élèves ne se saisissent pas ici : ils sont déjà comptés en caisse.',
                'Un « Versement de la caisse » à la banque est un transfert : il n\'est pas ajouté une seconde fois au total des entrées.',
                'Les banques sont les mêmes que pour les dépenses (Paramètres › Banques).',
            ],
            'demo' => null,
        ],
        'bilan-financier' => [
            'titre' => 'Voir et imprimer le bilan financier général',
            'menu' => 'Bilans › Bilan financier général',
            'route' => '/tabs/bilans/financier',
            'droit' => 'rapports.voir (poste qui voit les finances)',
            'mots' => 'bilan financier general global finances annee synthese resultat solde caisse banque recettes depenses',
            'etapes' => [
                'Ouvrez Bilans › Bilan financier général.',
                'Lisez la synthèse (frais, dettes, caisse, banque, résultat de l\'année) puis le détail : recettes par type de frais, niveaux, modes de paiement, caissiers, mois, dépenses, entrées banque, réductions.',
                'Cliquez sur « Imprimer le bilan » pour le document complet.',
            ],
            'conseils' => ['Les versements de la caisse à la banque ne comptent pas deux fois dans le total des entrées.'],
            'demo' => null,
        ],
        'bilans' => [
            'titre' => 'Les points et bilans (menu Bilans)',
            'menu' => 'Bilans',
            'route' => '/tabs/bilans/financier',
            'droit' => 'selon le point',
            'mots' => 'bilan bilans point points reductions depenses entrees financier general imprimer impression generale periode',
            'etapes' => [
                'Le menu Bilans regroupe : Point inscriptions, Point paiements, Point dettes, Point réductions, Point dépenses, Point entrées, Bilan financier général et Rapports.',
                'Chaque point a les mêmes boutons : « Filtres » (période : jour, dates, mois, année ; critères d\'élèves sauf dépenses et entrées), « Imprimer le point » et « Impression générale » (tout le détail).',
                'Point dépenses et Point entrées : colonnes caisse, banque et total.',
                'Bilan financier général : synthèse de l\'année (frais, dettes, caisse, banque, résultat) puis le détail, avec « Imprimer le bilan ».',
            ],
            'conseils' => ['Un point absent du menu = un droit absent du poste (ex. « Voir les entrées », « Voir les dépenses »).'],
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
