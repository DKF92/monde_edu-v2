import {
  homeOutline,
  peopleOutline,
  schoolOutline,
  personAddOutline,
  barChartOutline,
  timeOutline,
  calendarNumberOutline,
  cashOutline,
  receiptOutline,
  pieChartOutline,
  personCircleOutline,
  settingsOutline,
  shieldCheckmarkOutline,
  keyOutline,
  listOutline,
  walletOutline,
  addCircleOutline,
  alertCircleOutline,
  statsChartOutline,
  globeOutline,
  pricetagOutline,
  enterOutline,
} from 'ionicons/icons';

export type Teinte = 'bleu' | 'vert' | 'violet' | 'orange' | 'rose' | 'cyan';

export interface EntreeNavigation {
  libelle: string;
  /** Sous-titre des tuiles d'acces rapide sur l'accueil. */
  description: string;
  icone: string;
  teinte: Teinte;
  route: string;
  /** L'entree est visible si l'utilisateur a AU MOINS une de ces permissions
   * (tableau vide = visible par tous). */
  permissions: string[];
  /** Et au moins une de celles-ci (ex : bilan financier = rapports ET finances). */
  aussi?: string[];
  /** false tant que l'ecran n'est pas developpe : l'entree reste visible
   * (l'utilisateur voit ce qui arrive) mais n'est pas cliquable. */
  disponible: boolean;
  /** Proposee dans la barre d'onglets du bas sur mobile (4 maximum). */
  barreMobile?: boolean;
}

export interface SectionNavigation {
  titre: string | null;
  entrees: EntreeNavigation[];
}

/**
 * Source unique de la navigation : menu lateral (bureau), menu coulissant et
 * barre d'onglets (mobile), tuiles d'acces rapide de l'accueil.
 *
 * Pour brancher un nouvel ecran : ajouter sa route dans tabs.routes.ts puis
 * passer `disponible` a true ici.
 */
export const NAVIGATION: SectionNavigation[] = [
  {
    titre: null,
    entrees: [
      {
        libelle: 'Accueil',
        description: 'Tableau de bord',
        icone: 'home-outline',
        teinte: 'bleu',
        route: '/tabs/dashboard',
        permissions: [],
        disponible: true,
        barreMobile: true,
      },
    ],
  },
  {
    titre: 'Scolarité',
    entrees: [
      {
        libelle: 'Élèves',
        description: 'Voir la liste',
        icone: 'people-outline',
        teinte: 'bleu',
        route: '/tabs/eleves',
        permissions: ['eleves.voir'],
        disponible: true,
        barreMobile: true,
      },
      {
        libelle: 'Classes',
        description: 'Listes et effectifs',
        icone: 'school-outline',
        teinte: 'cyan',
        route: '/tabs/classes',
        permissions: ['classes.voir', 'classes.gerer'],
        disponible: true,
      },
      {
        libelle: 'Inscriptions',
        description: 'Nouvelle inscription',
        icone: 'person-add-outline',
        teinte: 'vert',
        route: '/tabs/inscriptions',
        permissions: ['inscriptions.gerer', 'inscriptions.voir'],
        disponible: true,
      },
      {
        libelle: 'Mises à jour en ligne',
        description: 'Changements repris du site de l\'État',
        icone: 'globe-outline',
        teinte: 'bleu',
        route: '/tabs/inscriptions/mises-a-jour',
        permissions: ['inscriptions.gerer', 'inscriptions.voir', 'reglements.voir', 'reglements.modifier'],
        disponible: true,
      },
      {
        libelle: 'Évaluations',
        description: 'Notes, résultats, bulletins',
        icone: 'bar-chart-outline',
        teinte: 'violet',
        route: '/tabs/evaluations',
        permissions: ['notes.saisir', 'notes.saisir_toutes', 'notes.voir', 'moyennes.gerer', 'bulletins.voir'],
        disponible: true,
      },
      {
        libelle: 'Absences',
        description: 'Appel et conduite',
        icone: 'time-outline',
        teinte: 'orange',
        route: '/tabs/absences',
        permissions: ['absences.gerer'],
        disponible: true,
      },
      {
        libelle: 'Emploi du temps',
        description: 'Consulter',
        icone: 'calendar-number-outline',
        teinte: 'cyan',
        route: '/tabs/emploi-du-temps',
        permissions: ['emplois_du_temps.gerer'],
        disponible: false,
      },
    ],
  },
  {
    titre: 'Finances',
    entrees: [
      {
        // Guichet : le matricule de l'eleve est demande sur la page.
        libelle: 'Encaisser',
        description: 'Nouveau paiement',
        icone: 'add-circle-outline',
        teinte: 'vert',
        route: '/tabs/paiements/encaisser',
        permissions: ['reglements.encaisser'],
        disponible: true,
      },
      {
        // Droit "Voir les paiements" : liste de tous les paiements.
        libelle: 'Paiements',
        description: 'Liste des paiements',
        icone: 'list-outline',
        teinte: 'cyan',
        route: '/tabs/paiements/liste',
        permissions: ['reglements.voir'],
        disponible: true,
      },
      {
        // Situation de tous les eleves : frais de l'annee et dettes.
        libelle: 'Reste à payer',
        description: 'Situation des élèves',
        icone: 'wallet-outline',
        teinte: 'orange',
        route: '/tabs/paiements/reste-a-payer',
        permissions: ['reglements.voir'],
        disponible: true,
      },
      {
        // Eleves inscrits qui ont une dette des annees precedentes (V1 liste_dette).
        libelle: 'Dettes',
        description: 'Liste des dettes',
        icone: 'alert-circle-outline',
        teinte: 'rose',
        route: '/tabs/paiements/dettes',
        permissions: ['reglements.voir', 'dettes.gerer'],
        disponible: true,
      },
      {
        // Reductions et cas sociaux accordes (V1 liste_reduction / liste_cas).
        libelle: 'Réductions',
        description: 'Réductions et cas sociaux',
        icone: 'pricetag-outline',
        teinte: 'violet',
        route: '/tabs/reductions',
        permissions: ['reductions.gerer', 'reductions.voir'],
        disponible: true,
      },
      {
        libelle: 'Dépenses',
        description: 'Sorties de caisse',
        icone: 'receipt-outline',
        teinte: 'rose',
        route: '/tabs/depenses',
        permissions: ['depenses.gerer', 'depenses.voir'],
        disponible: true,
      },
      {
        // Entrees banque saisies ; entrees caisse = paiements (total par caissier).
        libelle: 'Entrées',
        description: 'Caisse et banque',
        icone: 'enter-outline',
        teinte: 'vert',
        route: '/tabs/entrees',
        permissions: ['entrees.gerer', 'entrees.voir'],
        disponible: true,
      },
    ],
  },
  {
    titre: 'Bilans',
    entrees: [
      {
        // Tableau passes / pas passes a la caisse / cas, affectes et non affectes.
        libelle: 'Point inscriptions',
        description: 'Passés à la caisse ou non',
        icone: 'stats-chart-outline',
        teinte: 'bleu',
        route: '/tabs/inscriptions/point',
        permissions: ['inscriptions.gerer', 'rapports.voir'],
        disponible: true,
      },
      {
        // Droit "Encaisser" (Rôles > Finances) : point de ses encaissements (ancienne entree « Caisse »).
        libelle: 'Point paiements',
        description: 'Point des encaissements',
        icone: 'cash-outline',
        teinte: 'vert',
        route: '/tabs/paiements',
        permissions: ['reglements.encaisser'],
        disponible: true,
      },
      {
        // Dettes encaissees et situation des dettes (V1 Point_dette).
        libelle: 'Point dettes',
        description: 'Encaissements et situation',
        icone: 'stats-chart-outline',
        teinte: 'rose',
        route: '/tabs/paiements/point-dettes',
        permissions: ['reglements.voir', 'dettes.gerer'],
        disponible: true,
      },
      {
        // Reductions et cas sociaux accordes sur la periode (meme page que les autres points).
        libelle: 'Point réductions',
        description: 'Réductions et cas sociaux',
        icone: 'pricetag-outline',
        teinte: 'violet',
        route: '/tabs/bilans/reductions',
        permissions: ['reductions.gerer', 'reductions.voir'],
        disponible: true,
      },
      {
        libelle: 'Point dépenses',
        description: 'Par catégorie, caisse et banque',
        icone: 'receipt-outline',
        teinte: 'orange',
        route: '/tabs/bilans/depenses',
        permissions: ['depenses.voir'],
        disponible: true,
      },
      {
        libelle: 'Point entrées',
        description: 'Caisse et banque',
        icone: 'enter-outline',
        teinte: 'cyan',
        route: '/tabs/bilans/entrees',
        permissions: ['entrees.voir'],
        disponible: true,
      },
      {
        libelle: 'Bilan financier général',
        description: 'Toutes les opérations de l\'année',
        icone: 'bar-chart-outline',
        teinte: 'bleu',
        route: '/tabs/bilans/financier',
        permissions: ['rapports.voir'],
        aussi: ['reglements.voir', 'depenses.voir'],
        disponible: true,
      },
      {
        libelle: 'Rapports',
        description: 'Statistiques',
        icone: 'pie-chart-outline',
        teinte: 'violet',
        route: '/tabs/rapports',
        permissions: ['rapports.voir'],
        disponible: true,
      },
    ],
  },
  {
    titre: 'Administration',
    entrees: [
      {
        libelle: 'Utilisateurs',
        description: 'Comptes et postes',
        icone: 'shield-checkmark-outline',
        teinte: 'orange',
        route: '/tabs/utilisateurs',
        permissions: ['utilisateurs.gerer'],
        disponible: true,
      },
      {
        libelle: 'Rôles',
        description: 'Postes et droits',
        icone: 'key-outline',
        teinte: 'violet',
        route: '/tabs/roles',
        permissions: ['roles.gerer'],
        disponible: true,
      },
      {
        libelle: 'Paramètres',
        description: 'Établissement, années, paiements…',
        icone: 'settings-outline',
        teinte: 'bleu',
        route: '/tabs/parametres',
        permissions: ['classes.gerer', 'matieres.gerer', 'notes.arreter', 'etablissement.gerer', 'tarifs.gerer', 'annees_scolaires.gerer', 'sms.gerer', 'depenses.modifier'],
        disponible: true,
      },
    ],
  },
];

/** Entree "Mon profil" : hors sections, affichee en bas du menu. */
export const ENTREE_PROFIL: EntreeNavigation = {
  libelle: 'Mon profil',
  description: 'Compte et préférences',
  icone: 'person-circle-outline',
  teinte: 'bleu',
  route: '/tabs/profil',
  permissions: [],
  disponible: true,
};

/** A passer a addIcons() par les composants qui affichent la navigation. */
export const ICONES_NAVIGATION = {
  homeOutline,
  peopleOutline,
  schoolOutline,
  personAddOutline,
  barChartOutline,
  timeOutline,
  calendarNumberOutline,
  cashOutline,
  receiptOutline,
  pieChartOutline,
  personCircleOutline,
  settingsOutline,
  shieldCheckmarkOutline,
  keyOutline,
  listOutline,
  walletOutline,
  addCircleOutline,
  alertCircleOutline,
  statsChartOutline,
  globeOutline,
  pricetagOutline,
  enterOutline,
};
