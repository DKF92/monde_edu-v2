import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { DocumentService } from './document.service';
import { CaisseService } from './caisse.service';
import { BilanCaisse, FiltresBilan, PeriodeBilan } from '../models/caisse.model';

export type ModePoint = 'inscriptions' | 'dettes';
export type ColonnePoint = 'affecte' | 'non_affecte' | 'total';

export interface LignePoint {
  cle: string;
  libelle: string;
  affecte: number;
  non_affecte: number;
  total: number;
  /** Unite propre a la ligne (sinon celle du point). */
  unite?: 'eleves' | 'montant';
}

export interface SectionPoint {
  cle: string;
  titre: string;
  sous_titre: string | null;
  lignes: LignePoint[];
  total: LignePoint | null;
  niveau_id?: number;
}

/** Point des inscrits / des dettes (tableaux du point de caisse). */
export interface Point extends Pick<BilanCaisse, 'mois_disponibles' | 'criteres' | 'criteres_libelle' | 'criteres_disponibles' | 'annee' | 'genere_le'> {
  titre: string;
  periode: PeriodeBilan;
  unite: 'eleves' | 'montant';
  colonnes: Record<ColonnePoint, string>;
  sections: SectionPoint[];
  caissier_id: number | null;
  caissier: string | null;
  /** Point des dettes : caissiers ayant encaisse des dettes. */
  caissiers?: { id: number; nom: string }[];
  nombre_paiements?: number;
}

export interface FiltresPoint extends FiltresBilan {
  caissier_id?: number | null;
}

export interface EleveInscrit {
  inscription_id: number;
  eleve_id: number;
  matricule: string;
  nom: string;
  prenoms: string;
  sexe: string;
  affecte: boolean;
  redoublant: boolean;
  niveau: string;
  classe: string | null;
  date_inscription: string;
  du: number;
  paye: number;
  reste: number;
  etat: 'paye' | 'cas' | 'non_paye';
}

@Injectable({ providedIn: 'root' })
export class PointsService {
  private readonly http = inject(HttpClient);
  private readonly api = environment.apiUrl;
  private readonly documents = inject(DocumentService);
  private readonly caisse = inject(CaisseService);

  point(mode: ModePoint, filtres: FiltresPoint): Observable<Point> {
    return this.http.get<{ data: Point }>(`${this.api}/points/${mode}`, { params: this.params(filtres) }).pipe(map((r) => r.data));
  }

  async imprimer(mode: ModePoint, filtres: FiltresPoint, titre: string, general = false): Promise<void> {
    let params = this.params(filtres);
    if (general) params = params.set('general', 1);
    await this.documents.afficher({ titre, chemin: `/points/${mode}/document`, params, nomFichier: `point-${mode}${general ? '-general' : ''}` });
  }

  eleves(filtres: FiltresPoint, etat: string | null, affecte: boolean | null): Observable<{ titre: string; data: EleveInscrit[] }> {
    return this.http.get<{ titre: string; data: EleveInscrit[] }>(`${this.api}/points/inscriptions/eleves`, { params: this.paramsEleves(filtres, etat, affecte) });
  }

  async imprimerEleves(filtres: FiltresPoint, etat: string | null, affecte: boolean | null, titre: string): Promise<void> {
    await this.documents.afficher({ titre, chemin: '/points/inscriptions/eleves', params: this.paramsEleves(filtres, etat, affecte), nomFichier: 'liste-inscrits' });
  }

  private paramsEleves(filtres: FiltresPoint, etat: string | null, affecte: boolean | null): HttpParams {
    let params = this.params(filtres);
    if (etat) params = params.set('etat', etat);
    if (affecte !== null) params = params.set('affecte', affecte ? 1 : 0);
    return params;
  }

  private params(f: FiltresPoint): HttpParams {
    let params = new HttpParams().set('periode', f.periode);
    if (f.du) params = params.set('du', f.du);
    if (f.au) params = params.set('au', f.au);
    if (f.mois) params = params.set('mois', f.mois);
    if (f.caissier_id) params = params.set('caissier_id', f.caissier_id);
    return this.caisse.parametresCriteres(params, f);
  }
}
