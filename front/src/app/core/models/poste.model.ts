/** Poste de l'etablissement (administration des postes, menu "Rôles"). */
export interface Poste {
  id: number;
  nom: string;
  /** Identifiant stable des postes standard (null = poste cree par l'ecole). */
  code: string | null;
  description: string | null;
  standard: boolean;
  super_admin: boolean;
  is_active: boolean;
  /** Poste protege : attribue uniquement par le Super admin. */
  est_sensible: boolean;
  lie_niveaux: boolean;
  lie_matieres: boolean;
  permissions: string[];
  nombre_utilisateurs: number;
  /** false : poste protege, modifiable seulement par le Super admin. */
  modifiable: boolean;
}

export interface PosteDetail extends Poste {
  utilisateurs: {
    id: number;
    nom: string;
    prenoms: string | null;
    email: string | null;
    matricule: string | null;
    statut: 'actif' | 'inactif';
  }[];
}

export interface Droit {
  nom: string;
  libelle: string;
  description: string | null;
  /** Droit reserve (ex: gerer les postes proteges). */
  reserve: boolean;
  /** L'administrateur connecte peut-il accorder ce droit ? */
  accordable: boolean;
}

export interface CatalogueDroits {
  peut_gerer_sensibles: boolean;
  groupes: { groupe: string; droits: Droit[] }[];
}

export interface PosteSaisie {
  nom: string;
  description: string | null;
  est_sensible: boolean;
  lie_niveaux: boolean;
  lie_matieres: boolean;
  permissions: string[];
}
