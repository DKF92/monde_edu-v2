import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { DossierEleve, PageEleves } from '../models/eleve.model';

export interface FiltresEleves {
  page: number;
  par_page: number;
  recherche?: string;
  /** inscrits / non_inscrits pour l'annee de travail. */
  inscription?: 'inscrits' | 'non_inscrits' | null;
  sexe?: 'M' | 'F' | null;
}

/** Eleves de l'etablissement (droit eleves.voir). */
@Injectable({ providedIn: 'root' })
export class EleveService {
  private readonly http = inject(HttpClient);
  private readonly url = `${environment.apiUrl}/eleves`;

  lister(filtres: FiltresEleves): Observable<PageEleves> {
    let params = new HttpParams().set('page', filtres.page).set('par_page', filtres.par_page);
    if (filtres.recherche?.trim()) params = params.set('recherche', filtres.recherche.trim());
    if (filtres.inscription) params = params.set('inscription', filtres.inscription);
    if (filtres.sexe) params = params.set('sexe', filtres.sexe);
    return this.http.get<PageEleves>(this.url, { params });
  }

  dossier(id: number): Observable<DossierEleve> {
    return this.http.get<{ data: DossierEleve }>(`${this.url}/${id}`).pipe(map((r) => r.data));
  }
}
