import { FicheEleve } from './inscription.model';

export type ModePaiement = 'especes' | 'mobile_money' | 'cheque' | 'virement';

export const LIBELLES_MODE: Record<ModePaiement, string> = {
  especes: 'Espèces',
  mobile_money: 'Mobile money',
  cheque: 'Chèque',
  virement: 'Virement',
};

/** Eleve trouve au guichet. */
export interface ResultatCaisse {
  id: number;
  eleve: { id: number; matricule: string; nom: string; prenoms: string; sexe: 'M' | 'F' | null; photo_url: string | null };
  classe: string | null;
  niveau: string | null;
  statut: number;
  reste: number;
  dettes: number;
}

export interface ReglementCaisse {
  id: number;
  numero_recu: string;
  numero_versement: number | null;
  date: string;
  date_expiration: string | null;
  montant: number;
  mode: ModePaiement;
  inscription_id: number | null;
  eleve: { id: number; matricule: string; nom: string; prenoms: string } | null;
  classe: string | null;
  caissier: string | null;
  lignes: { libelle: string; dette: boolean; montant: number }[] | null;
  /** Liste d'une cellule du bilan : part de ce paiement pour les frais filtres. */
  part?: number | null;
}

/** Bornes du prochain versement (V1 : 1er >= minimum de la grille, 6 au plus). */
export interface BornesVersement {
  numero: number;
  maximum: number;
  minimum: number;
  doit_solder: boolean;
  versements_max: number;
}

export interface SituationCaisse {
  id: number;
  annee: string | null;
  eleve: FicheEleve & { id: number; photo_url: string | null };
  classe: string | null;
  niveau: string | null;
  affecte: boolean;
  statut: number;
  total_du: number;
  total_reduit: number;
  total_paye: number;
  reste: number;
  frais: { id: number; libelle: string; montant_du: number; montant_reduit: number; montant_paye: number; reste: number }[];
  fournitures: { id: number; libelle: string; quantite_due: number; quantite_remise: number }[];
  dettes: { id: number; annee: string | null; reste: number }[];
  versements: ReglementCaisse[];
  bornes: BornesVersement | null;
  sms: { simulation: boolean; actif: boolean; credit: number };
}

export interface SaisieEncaissement {
  montant: number;
  montant_dette: number;
  mode_paiement: ModePaiement;
  date_paiement: string | null;
  date_expiration: string | null;
  fournitures: { id: number; quantite_remise: number }[];
}

export interface ResultatEncaissement {
  reglement: ReglementCaisse;
  sms: { statut: 'envoye' | 'simule' | 'desactive' | 'credit' | 'sans_numero' | 'echec'; message: string; numero?: string };
  situation: SituationCaisse;
}

export interface PageJournal {
  data: ReglementCaisse[];
  /** Somme des parts (liste d'une cellule du bilan), sinon null. */
  total_parts: number | null;
  /** Dates retenues par le serveur pour la periode. */
  du: string | null;
  au: string | null;
  total: number;
  page: number;
  par_page: number;
  totaux: { nombre: number; montant: number; par_mode: Record<ModePaiement, number> };
  caissiers: { id: number; nom: string }[];
  /** Portee appliquee par l'API : ses encaissements (droit Encaisser) ou tous. */
  mes_encaissements: boolean;
  /** Recherche par matricule (toute l'annee, tous encaisseurs). */
  matricule: string | null;
}

/** Periode du point de caisse. */
export interface PeriodeBilan {
  periode: 'jour' | 'dates' | 'mois' | 'annee';
  du: string | null;
  au: string | null;
  mois: string | null;
  libelle: string;
}

export interface LigneBilan {
  cle: string;
  type_frais_id: number | null;
  dette: boolean;
  libelle: string;
  nature: string;
  actif: boolean;
  applicable_affecte: boolean;
  applicable_non_affecte: boolean;
  affecte: number;
  non_affecte: number;
  total: number;
}

/** Point des paiements encaisses par l'utilisateur connecte. */
export interface BilanCaisse {
  periode: PeriodeBilan;
  titre: string;
  annee: string;
  mois_disponibles: { valeur: string; libelle: string }[];
  criteres: CriteresBilan;
  criteres_libelle: string;
  criteres_disponibles: {
    cycles: { valeur: string; libelle: string }[];
    niveaux: { id: number; libelle: string; cycle: string }[];
    classes: { id: number; libelle: string; niveau_id: number }[];
  };
  caissier: { id: number; nom: string };
  genere_le: string;
  lignes: LigneBilan[];
  totaux: { affecte: number; non_affecte: number; total: number };
  nombre_paiements: number;
}

/** Criteres eleve : sexe, redoublant et UN seul parmi cycle / niveau / classe. */
export interface CriteresBilan {
  sexe?: 'M' | 'F' | null;
  redoublant?: boolean | null;
  cycle?: string | null;
  niveau_id?: number | null;
  classe_id?: number | null;
}

export interface FiltresBilan extends CriteresBilan {
  periode: PeriodeBilan['periode'];
  du?: string | null;
  au?: string | null;
  mois?: string | null;
}

/** Correction d'un paiement (historique). */
export interface CorrectionPaiement {
  action: 'modification' | 'suppression';
  motif: string;
  avant: { montant_total: number; mode_paiement: ModePaiement; date_paiement: string } | null;
  apres: { montant_total: number; mode_paiement: ModePaiement; date_paiement: string } | null;
  date: string;
  par: string;
}

/** Detail d'un paiement : paiement, situation de l'inscription, historique. */
export interface DetailPaiement {
  reglement: ReglementCaisse;
  situation: SituationCaisse | null;
  /** Bornes du montant sans ce paiement (formulaire de modification). */
  modification: {
    montant: number;
    montant_dette: number;
    maximum: number;
    minimum: number;
    doit_solder: boolean;
    dettes_maximum: number;
  } | null;
  historique: CorrectionPaiement[];
}

export interface SaisieModification {
  motif: string;
  montant: number;
  montant_dette: number;
  mode_paiement: ModePaiement;
  date_paiement: string | null;
  date_expiration: string | null;
}
