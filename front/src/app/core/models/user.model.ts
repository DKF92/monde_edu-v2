export interface Etablissement {
  id: number;
  code: string;
  nom: string;
  sigle: string | null;
  type_etablissement: string;
  logo_path: string | null;
  ville: string | null;
  statut: string;
}

export interface User {
  id: number;
  matricule: string | null;
  name: string;
  prenoms: string | null;
  email: string;
  telephone: string | null;
  photo_path: string | null;
  etablissement_id: number | null;
  doit_changer_mot_de_passe: boolean;
  roles?: string[];
  permissions?: string[];
}

export interface LoginResponse {
  token: string;
  user: User;
  etablissements: Etablissement[];
}

export interface AnneeScolaire {
  id: number;
  libelle: string;
  is_active: boolean;
  is_cloturee: boolean;
}

export interface Periode {
  id: number;
  annee_scolaire_id: number;
  type_decoupage: 'trimestre' | 'semestre';
  numero: number;
  libelle: string;
  is_active: boolean;
  is_cloturee: boolean;
}

/** Poste (role) de l'utilisateur dans un etablissement : c'est lui qui
 * determine le menu et les droits, comme le poste du compte en V1. */
export interface Poste {
  id: number;
  nom: string;
  permissions: string[];
}

/** Reponse de GET /contexte pour un etablissement donne. */
export interface ContexteEtablissement {
  etablissement: Etablissement;
  annees: (AnneeScolaire & { periodes: Periode[] })[];
  postes: Poste[];
}

/** Droit (attribuable a n'importe quel poste) de choisir la periode
 * (trimestre/semestre) de travail dans la modale d'espace de travail. Sans
 * lui, l'utilisateur travaille sur la periode en cours de l'annee. */
export const PERMISSION_CHOIX_PERIODE = 'periodes.choisir';
