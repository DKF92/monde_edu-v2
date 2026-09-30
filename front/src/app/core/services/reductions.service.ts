import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { DocumentService } from './document.service';
import { CaisseService } from './caisse.service';
import { BilanCaisse, CriteresBilan } from '../models/caisse.model';

export type CibleReduction = 'inscription' | 'dette';

export interface ReductionAccordee {
  lot: string;
  date: string;
  montant: number;
  cible: CibleReduction;
  type: string;
  code: string | null;
  dette_annulee: boolean;
  motif: string | null;
  accorde_par: string | null;
  inscription_id: number;
  eleve_id: number;
  matricule: string;
  nom: string;
  prenoms: string;
  sexe: string;
  affecte: boolean;
  niveau_id: number;
  classe_id: number | null;
  niveau: string;
  classe: string | null;
}

export interface PageReductions {
  data: ReductionAccordee[];
  annee: string;
  totaux: { nombre: number; eleves: number; montant: number; inscription: number; dette: number; cas: number };
  types: { id: number; libelle: string; code: string; is_active: boolean }[];
  criteres_libelle: string;
  criteres_disponibles: BilanCaisse['criteres_disponibles'];
}

export interface FiltresReductions extends CriteresBilan {
  cible?: CibleReduction | null;
  type_reduction_id?: number | null;
  du?: string | null;
  au?: string | null;
  affecte?: boolean | null;
  recherche?: string;
}

export interface FraisReduction {
  id: number;
  libelle: string;
  principal: boolean;
  montant_du: number;
  montant_reduit: number;
  montant_paye: number;
  reste: number;
}

export interface TypeReductionEleve {
  id: number;
  libelle: string;
  code: string;
  base: 'frais_principaux' | 'total';
  bornes: { min: number; max: number };
  /** Frais reduits, dans l'ordre d'imputation. */
  ordre: number[];
}

export interface DetteReduction {
  id: number;
  annee: string | null;
  montant: number;
  reduit: number;
  paye: number;
  reste: number;
}

export interface EleveReduction {
  eleve: { id: number; matricule: string; nom: string; prenoms: string; sexe: string; photo_url?: string | null };
  inscription: { id: number; classe: string | null; niveau: string | null; affecte: boolean; date: string | null; total_du: number; total_reduit: number; total_paye: number; reste: number };
  frais?: FraisReduction[];
  types?: TypeReductionEleve[];
  dettes?: DetteReduction[];
}

/** Menu Reductions (droit reductions.gerer). */
@Injectable({ providedIn: 'root' })
export class ReductionsService {
  private readonly http = inject(HttpClient);
  private readonly api = environment.apiUrl;
  private readonly documents = inject(DocumentService);
  private readonly caisse = inject(CaisseService);

  liste(filtres: FiltresReductions): Observable<PageReductions> {
    return this.http.get<PageReductions>(`${this.api}/reductions`, { params: this.params(filtres) });
  }

  async imprimer(filtres: FiltresReductions, general: boolean): Promise<void> {
    let params = this.params(filtres);
    if (general) params = params.set('general', 1);
    await this.documents.afficher({
      titre: general ? 'Point général des réductions' : 'Liste des réductions',
      chemin: '/reductions/document',
      params,
      nomFichier: general ? 'point-reductions' : 'liste-reductions',
    });
  }

  eleve(matricule: string, cible: CibleReduction): Observable<EleveReduction> {
    return this.http.get<EleveReduction>(`${this.api}/reductions/eleve`, { params: { matricule, cible } });
  }

  accorder(saisie: { inscription_id: number; type_reduction_id: number; montant: number; motif: string }): Observable<{ lot: string; message: string }> {
    return this.http.post<{ lot: string; message: string }>(`${this.api}/reductions`, saisie);
  }

  accorderDette(saisie: { dette_id: number; montant: number | null; annuler: boolean; motif: string }): Observable<{ lot: string; message: string }> {
    return this.http.post<{ lot: string; message: string }>(`${this.api}/reductions/dette`, saisie);
  }

  supprimer(lot: string): Observable<{ message: string }> {
    return this.http.delete<{ message: string }>(`${this.api}/reductions/${lot}`);
  }

  private params(f: FiltresReductions): HttpParams {
    let p = new HttpParams();
    if (f.cible) p = p.set('cible', f.cible);
    if (f.type_reduction_id) p = p.set('type_reduction_id', f.type_reduction_id);
    if (f.du) p = p.set('du', f.du);
    if (f.au) p = p.set('au', f.au);
    if (f.affecte !== null && f.affecte !== undefined) p = p.set('affecte', f.affecte ? 1 : 0);
    if (f.recherche?.trim()) p = p.set('recherche', f.recherche.trim());
    return this.caisse.parametresCriteres(p, f);
  }
}
