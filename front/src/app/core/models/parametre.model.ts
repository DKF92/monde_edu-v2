/** Effectifs maximums (le plus precis l'emporte : classe > niveau > etablissement). */
export interface ParametresClasses {
  annee: string | null;
  effectif_max_classe: number | null;
  /** Numerotation des classes d'un niveau : 6EME 1, 6EME 2 ou 6EME A, 6EME B. */
  numerotation_classes: 'chiffres' | 'lettres';
  niveaux: {
    id: number;
    libelle: string;
    effectif_max: number | null;
    classes: { id: number; libelle: string; capacite: number | null; effectif: number }[];
  }[];
}

/** A partir de quand un eleve apparait dans la liste de sa classe. */
export type StatutVisibleClasse = 'classe_attribuee' | 'premier_paiement' | 'solde';

/** Parametres > Inscriptions. Format du matricule : 9 = chiffre, A = lettre. */
export interface ParametresInscriptions {
  statut_visible_classe: StatutVisibleClasse;
  formats_matricule: { format: string; description: string }[];
  verification_en_ligne: boolean;
  code_mena: string | null;
}

export interface ParametresInscriptionsSaisie {
  statut_visible_classe: StatutVisibleClasse;
  formats_matricule: string[];
  verification_en_ligne: boolean;
  code_mena: string | null;
}

export interface TypeFrais {
  id: number;
  code: string;
  libelle: string;
  nature: 'inscription' | 'scolarite' | 'annexe' | 'en_nature' | 'dette';
  ordre: number;
  is_obligatoire: boolean;
  /** Desactive : n'apparait plus dans les montants d'inscription. */
  is_active: boolean;
  /** Le frais concerne-t-il ce type d'eleve ? (grise sinon) */
  applicable_affecte: boolean;
  applicable_non_affecte: boolean;
}

export interface GrilleColonne {
  /** Calcule par le serveur (annexes, + inscription pour un non affecte). */
  montant_minimum: number;
  /** Montant par type de frais (cle = id du type). */
  montants: Record<number, number>;
}

export interface TarifsNiveaux {
  annee: string | null;
  types_frais: TypeFrais[];
  niveaux: {
    id: number;
    libelle: string;
    /** null = pas encore saisi. */
    affecte: GrilleColonne | null;
    non_affecte: GrilleColonne | null;
  }[];
}

export interface TypeReduction {
  id: number;
  code: string;
  libelle: string;
  /** frais_principaux = inscription/scolarite ; total = annexes comprises. */
  base: 'frais_principaux' | 'total';
  montant_minimum: number;
  /** La reduction doit au moins couvrir le reste des frais principaux (V1 "cas"). */
  minimum_frais_principaux: boolean;
  plafond_pourcentage: number | null;
  is_active: boolean;
}

export type TypeReductionSaisie = Omit<TypeReduction, 'id' | 'code'>;

/** Parametres > Etablissement (V1 info_etablissement). */
export interface InfosEtablissement {
  code: string;
  nom: string;
  sigle: string | null;
  type_etablissement: string;
  slogan: string | null;
  telephone1: string | null;
  telephone2: string | null;
  email: string | null;
  site_web: string | null;
  boite_postale: string | null;
  quartier: string | null;
  ville: string | null;
  region: string | null;
  pays: string;
  indicatif_telephonique: string;
  /** En-tete des documents (gauche) ; adresse et telephone vides = ceux de l'etablissement. */
  entete_ministere: string | null;
  entete_direction: string | null;
  entete_adresse: string | null;
  entete_telephone: string | null;
  logo_url: string | null;
  statut: string;
  date_expiration_abonnement: string | null;
}

export type InfosEtablissementSaisie = Omit<InfosEtablissement, 'code' | 'logo_url' | 'statut' | 'date_expiration_abonnement'>;

export interface PeriodeGestion {
  id: number;
  numero: number;
  libelle: string;
  date_debut: string | null;
  date_fin: string | null;
  is_active: boolean;
  is_cloturee: boolean;
}

export interface AnneeGestion {
  id: number;
  libelle: string;
  date_debut: string | null;
  date_fin: string | null;
  is_active: boolean;
  is_cloturee: boolean;
  nombre_classes: number;
  nombre_inscriptions: number;
  type_decoupage: 'trimestre' | 'semestre' | null;
  periodes: PeriodeGestion[];
}

export interface NouvelleAnnee {
  libelle: string;
  date_debut: string | null;
  date_fin: string | null;
  type_decoupage: 'trimestre' | 'semestre';
  copier_classes: boolean;
  copier_tarifs: boolean;
  activer: boolean;
}

export interface ParametresSms {
  sms_actif: boolean;
  sms_expediteur: string | null;
  sms_credit: number;
  indicatif_telephonique: string;
  /** Fournisseur de la plateforme renseigne (sinon aucun envoi possible). */
  fournisseur_configure: boolean;
  peut_recharger: boolean;
  envoyes_ce_mois: number;
}

export interface PageServeur<T> {
  data: T[];
  total: number;
  page: number;
  taille: number;
}

export interface RechargementSms {
  id: number;
  date: string;
  montant: number;
  nombre_sms: number;
  commentaire: string | null;
  enregistre_par: string | null;
}

export interface MessageSms {
  id: number;
  date: string;
  numero: string;
  message: string;
  campagne: string | null;
  nombre_sms: number;
  statut: 'envoye' | 'echec';
  erreur: string | null;
  eleve: string | null;
  envoye_par: string | null;
}
