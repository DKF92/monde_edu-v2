export type StatutContrat = 'permanent' | 'vacataire' | 'stagiaire';

/** Utilisateur vu depuis l'administration de l'etablissement courant. */
export interface Utilisateur {
  id: number;
  matricule: string | null;
  nom: string;
  prenoms: string | null;
  email: string | null;
  telephone: string | null;
  sexe: 'M' | 'F' | null;
  statut: 'actif' | 'inactif';
  doit_changer_mot_de_passe: boolean;
  derniere_connexion_at: string | null;
  created_at: string | null;
  /** Le compte de l'administrateur connecte (postes et statut non modifiables). */
  est_moi: boolean;
  etablissement_principal: boolean;
  /** Postes occupes DANS l'etablissement courant. */
  postes: { id: number; nom: string; est_sensible: boolean }[];
  /** false : compte avec un poste protege, reserve au Super admin. */
  modifiable: boolean;
  /** Niveaux suivis cette annee (educateur). */
  niveaux: { id: number; libelle: string }[];
  /** Matieres enseignees dans l'etablissement (professeur). */
  matieres: { id: number; libelle: string }[];
  personnel: {
    diplome: string | null;
    statut_contrat: StatutContrat | null;
    date_embauche: string | null;
    nationalite: string | null;
    quartier: string | null;
  } | null;
}

export interface PosteOption {
  id: number;
  nom: string;
  is_active: boolean;
  /** Poste protege : attribue uniquement par le Super admin. */
  est_sensible: boolean;
  /** Le poste se rattache a des niveaux (educateur). */
  lie_niveaux: boolean;
  /** Le poste enseigne des matieres (professeur). */
  lie_matieres: boolean;
  /** L'administrateur connecte peut-il attribuer ce poste ? */
  attribuable: boolean;
}

/** Listes des formulaires d'utilisateur. */
export interface OptionsUtilisateurs {
  peut_gerer_sensibles: boolean;
  postes: PosteOption[];
  niveaux: { id: number; libelle: string; cycle: string }[];
  matieres: { id: number; code: string; libelle: string }[];
}

/** Donnees envoyees a la creation / modification. */
export interface UtilisateurSaisie {
  nom: string;
  prenoms: string | null;
  email: string;
  telephone: string | null;
  sexe: 'M' | 'F' | null;
  postes: number[];
  niveaux: number[];
  matieres: number[];
  diplome: string | null;
  statut_contrat: StatutContrat | null;
  date_embauche: string | null;
  nationalite: string | null;
  quartier: string | null;
}

/** Reponse de creation / reinitialisation : le mot de passe provisoire n'est
 * renvoye qu'une seule fois. */
export interface UtilisateurAvecMotDePasse {
  data: Utilisateur;
  mot_de_passe_provisoire: string;
}
