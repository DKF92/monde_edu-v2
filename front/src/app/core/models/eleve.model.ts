export interface Eleve {
  id: number;
  matricule: string;
  nom: string;
  prenoms: string;
  date_naissance: string | null;
  sexe: string | null;
  statut: string;
  photo_path: string | null;
}

export interface PageResultat<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

export interface ClasseResume {
  id: number;
  libelle: string;
  niveau: string | null;
  salle: string | null;
  effectif: number;
  capacite: number | null;
}

export interface EcheanceResume {
  date: string;
  libelle: string;
  detail: string;
  montant: number;
}

export interface StatsDashboard {
  annee_scolaire_id: number | null;
  annee_scolaire_active: string | null;
  periode: {
    id: number;
    libelle: string;
    date_debut: string | null;
    date_fin: string | null;
  } | null;
  effectif_eleves: number;
  nombre_classes: number;
  /** "mes" = classes de l'enseignant connecte, "toutes" = vue administration,
   * null = profil sans acces aux classes (caisse, comptabilite...). */
  classes: ClasseResume[];
  classes_portee: 'mes' | 'toutes' | null;
  classes_total: number;
  echeances: EcheanceResume[];
  /** null si l'utilisateur n'a pas le droit de voir les montants. */
  finances: {
    encaissements_du_jour: number;
    encaissements_du_mois: number;
    reste_a_recouvrer: number;
  } | null;
}

/** Ligne de la liste des eleves (tous les eleves connus de l'etablissement). */
export interface LigneEleve {
  id: number;
  matricule: string;
  nom: string;
  prenoms: string;
  sexe: 'M' | 'F' | null;
  date_naissance: string | null;
  photo_url: string | null;
  /** Inscription de l'annee de travail (null = pas inscrit cette annee). */
  inscription: { id: number; classe: string | null; niveau: string | null; statut: number } | null;
  derniere_annee: { annee: string; classe: string | null } | null;
  nombre_annees: number;
}

export interface PageEleves {
  data: LigneEleve[];
  total: number;
  page: number;
  par_page: number;
  derniere_page: number;
  compteurs: { total: number; inscrits: number; non_inscrits: number };
  annee: string | null;
}

export interface AnneeDossier {
  id: number;
  annee: string;
  en_cours: boolean;
  niveau: string | null;
  classe: string | null;
  statut: number;
  affecte: boolean;
  redoublant: boolean;
  boursier: boolean;
  langue_vivante_2: string | null;
  inscrit_en_ligne: boolean;
  date_inscription: string | null;
  enregistre_par: string | null;
  decision_finale: string | null;
  moyenne_annuelle: number | null;
  niveau_a_suivre: string | null;
  provenance: string | null;
  moyennes: { periode: string | null; moyenne: number | null; rang: number | null; mention: string | null }[];
  finances: {
    total_du: number;
    total_reduit: number;
    total_paye: number;
    reste: number;
    frais: { libelle: string; montant_du: number; montant_reduit: number; montant_paye: number }[];
  } | null;
}

export interface DossierEleve {
  eleve: {
    id: number;
    matricule: string;
    nom: string;
    prenoms: string;
    sexe: 'M' | 'F' | null;
    date_naissance: string | null;
    lieu_naissance: string | null;
    nationalite: string | null;
    telephone: string | null;
    quartier: string | null;
    particularites_medicales: string | null;
    orphelin_pere: boolean;
    orphelin_mere: boolean;
    statut: string;
    photo_url: string | null;
    parents: { lien_parente: string; nom_complet: string; telephone: string | null; profession: string | null; is_contact_principal: boolean; is_payeur: boolean }[];
  };
  parcours: AnneeDossier[];
  finances_visibles: boolean;
  reglements: { id: number; date: string | null; annee: string | null; numero_recu: string; montant: number; mode: string }[];
  dettes: { annee: string | null; montant: number; reste: number; annulee: boolean }[];
  documents: { id: number; type: string; url: string; date: string | null }[];
}
