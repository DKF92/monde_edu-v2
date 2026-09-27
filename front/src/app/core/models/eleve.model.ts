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

export interface StatsDashboard {
  annee_scolaire_active: string | null;
  effectif_eleves: number;
  nombre_classes: number;
  encaissements_du_jour: number;
  encaissements_du_mois: number;
}
