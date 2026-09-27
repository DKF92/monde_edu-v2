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

/** Permissions dont la presence indique un profil pedagogique (a qui on
 * propose le choix du trimestre). Les profils purement finance (caissier,
 * comptable, econome...) n'en ont aucune et n'ont donc pas ce choix. */
export const PERMISSIONS_PEDAGOGIQUES = ['notes.saisir', 'notes.voir', 'moyennes.gerer', 'absences.gerer'];
