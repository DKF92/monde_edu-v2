/** V1 inscription.inscr_termine : 0 creee sans classe, 1 classe choisie, 2 au moins un paiement, 3 solde. */
export type StatutInscription = 0 | 1 | 2 | 3;

export const STATUTS_INSCRIPTION: StatutInscription[] = [0, 1, 2, 3];

export type LienParente = 'pere' | 'mere' | 'tuteur_legal';

export interface ClasseInscription {
  id: number;
  libelle: string;
  niveau_id: number;
  langue_vivante_2: string | null;
  effectif: number;
  garcons: number;
  filles: number;
  limite: number | null;
  complete: boolean;
}

export interface NiveauInscription {
  id: number;
  code: string;
  libelle: string;
  ordre: number;
  classes: ClasseInscription[];
  /** Montants d'inscription renseignes pour ce niveau (affecte / non affecte). */
  tarifs: { affecte: boolean; non_affecte: boolean };
}

export interface OptionsInscription {
  annee: string | null;
  niveaux: NiveauInscription[];
  tous_niveaux: { id: number; code: string; libelle: string; ordre: number }[];
  niveaux_restreints: boolean;
  formats_matricule: string[];
  verification_en_ligne: boolean;
  langues: string[];
  decisions: string[];
}

export interface ParentEleve {
  lien_parente: LienParente;
  nom_complet: string;
  telephone: string | null;
  profession: string | null;
  is_contact_principal: boolean;
  is_payeur: boolean;
}

export interface FicheEleve {
  id?: number;
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
  photo_url?: string | null;
  parents: ParentEleve[];
}

export interface InscriptionPrecedente {
  id: number;
  annee: string | null;
  classe: string | null;
  niveau_id: number | null;
  niveau: string | null;
  affecte: boolean;
  langue_vivante_2: string | null;
  decision_finale: string | null;
  moyenne_annuelle: number | null;
  niveau_a_suivre_id: number | null;
  niveau_a_suivre: string | null;
  reste: number;
}

export interface DetteEleve {
  id: number | null;
  annee: string | null;
  montant: number;
  reste: number;
  /** Reste de l'annee precedente, transforme en dette a l'enregistrement. */
  a_creer: boolean;
}

export interface LigneInscription {
  id: number;
  eleve: { id: number; matricule: string; nom: string; prenoms: string; sexe: 'M' | 'F' | null; photo_url: string | null };
  niveau: { id: number; libelle: string } | null;
  classe: { id: number; libelle: string } | null;
  statut: StatutInscription;
  affecte: boolean;
  redoublant: boolean;
  inscrit_en_ligne: boolean;
  date_inscription: string | null;
  total_du: number;
  total_reduit: number;
  total_paye: number;
  reste: number;
}

export interface PageInscriptions {
  data: LigneInscription[];
  total: number;
  page: number;
  par_page: number;
  compteurs: Record<number, number>;
  /** Inscriptions de l'annee jamais verifiees en ligne. */
  sans_en_ligne: number;
}

/** Recu de preinscription sur le site de l'Etat. */
export interface RecuEnLigne {
  numero_recu?: string;
  annee?: string;
  matricule: string;
  nom?: string;
  prenoms?: string;
  date_naissance?: string;
  lieu_naissance?: string;
  sexe?: 'M' | 'F';
  affecte?: boolean;
  statut?: string;
  etablissement?: string;
  code_etablissement?: string;
  type_enseignement?: string;
  drena?: string;
  somme_payee?: number;
  date_paiement?: string;
  transaction?: string;
  contact_parent?: string;
  niveau_precedent?: string;
  moyenne?: number;
  decision_code?: string;
  decision?: string;
  niveau_suivant?: string;
  photo_url?: string;
}

export interface ResultatEnLigne {
  trouve: boolean;
  erreur?: string | null;
  donnees?: RecuEnLigne;
  niveau_precedent_id?: number | null;
  niveau_suivant_id?: number | null;
  autre_etablissement?: boolean;
}

export type ResultatMatricule =
  | { cas: 'nouveau'; matricule: string }
  | { cas: 'deja_inscrit'; matricule: string; eleve: FicheEleve; inscription: LigneInscription }
  | { cas: 'reinscription'; matricule: string; eleve: FicheEleve; precedente: InscriptionPrecedente | null; dettes: DetteEleve[] };

export interface FraisInscription {
  id: number;
  libelle: string;
  nature: string;
  montant_du: number;
  montant_reduit: number;
  montant_paye: number;
  reste: number;
}

export interface FournitureInscription {
  id: number;
  libelle: string;
  quantite_due: number;
  quantite_remise: number;
}

export interface DetailInscription extends Omit<LigneInscription, 'eleve'> {
  annee: string | null;
  eleve: FicheEleve & { id: number; photo_url: string | null };
  langue_vivante_2: string | null;
  boursier: boolean;
  etablissement_origine: string | null;
  classe_origine: string | null;
  decision_origine: string | null;
  moyenne_origine: number | null;
  decision_finale: string | null;
  niveau_a_suivre: string | null;
  inscription_en_ligne: RecuEnLigne | null;
  enregistre_par: string | null;
  precedente: InscriptionPrecedente | null;
  frais: FraisInscription[];
  fournitures: FournitureInscription[];
  dettes: DetteEleve[];
  montant_minimum: number | null;
  grille_definie: boolean;
  modifiable_tarif: boolean;
  classe_detail: ClasseInscription | null;
  etablissement: EnteteEtablissement | null;
}

export interface SaisieInscription {
  eleve: Omit<FicheEleve, 'parents' | 'photo_url' | 'id'>;
  parents: ParentEleve[];
  inscription: {
    niveau_id: number | null;
    classe_id: number | null;
    affecte: boolean;
    redoublant: boolean;
    boursier: boolean;
    langue_vivante_2: string | null;
    etablissement_origine: string | null;
    classe_origine: string | null;
    decision_origine: string | null;
    moyenne_origine: number | null;
    inscrit_en_ligne: boolean;
    inscription_en_ligne: RecuEnLigne | null;
  };
  precedente?: { decision_finale: string | null; niveau_a_suivre_id: number | null; moyenne_annuelle: number | null } | null;
  /** Enregistrer la photo du recu de l'inscription en ligne. */
  photo_en_ligne?: boolean;
}

export const LIBELLES_STATUT: Record<number, string> = {
  0: 'En attente de choix de classe',
  1: 'En attente du 1er versement',
  2: 'En attente de solder',
  3: 'Soldé',
};

/** Couleur du badge de chaque statut. */
export const TONS_STATUT: Record<number, string> = { 0: 'attention', 1: 'violet', 2: 'info', 3: 'succes' };

export const LIBELLES_DECISION: Record<string, string> = {
  ADMIS: 'Admis(e)',
  REDOUBLE: 'Redouble',
  EXCLU: 'Exclu(e)',
};

export interface ApercuFrais {
  grille_definie: boolean;
  frais: { libelle: string; nature: string; montant: number }[];
  fournitures: { libelle: string; quantite: number }[];
  total: number;
  minimum: number | null;
}

export interface EnteteEtablissement {
  nom: string;
  slogan: string | null;
  logo_url: string | null;
  contacts: string;
}

/** Une inscription traitee par la mise a jour en ligne. */
export interface LigneMiseAJourEnLigne {
  id: number;
  eleve: string;
  trouve: boolean;
  remarques: string[];
}

export interface LotMiseAJourEnLigne {
  traitees: LigneMiseAJourEnLigne[];
  dernier_id: number;
  restantes: number;
  site_indisponible: string | null;
}
