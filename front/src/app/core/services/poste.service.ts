import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { CatalogueDroits, Poste, PosteDetail, PosteSaisie } from '../models/poste.model';

/** Administration des postes de l'etablissement courant. */
@Injectable({ providedIn: 'root' })
export class PosteService {
  private readonly http = inject(HttpClient);
  private readonly url = `${environment.apiUrl}/postes`;

  lister(): Observable<Poste[]> {
    return this.http.get<{ data: Poste[] }>(this.url).pipe(map((r) => r.data));
  }

  catalogue(): Observable<CatalogueDroits> {
    return this.http.get<CatalogueDroits>(`${this.url}/catalogue`);
  }

  detail(id: number): Observable<PosteDetail> {
    return this.http.get<{ data: PosteDetail }>(`${this.url}/${id}`).pipe(map((r) => r.data));
  }

  creer(saisie: PosteSaisie): Observable<Poste> {
    return this.http.post<{ data: Poste }>(this.url, saisie).pipe(map((r) => r.data));
  }

  modifier(id: number, saisie: PosteSaisie): Observable<PosteDetail> {
    return this.http.put<{ data: PosteDetail }>(`${this.url}/${id}`, saisie).pipe(map((r) => r.data));
  }

  changerStatut(id: number, isActive: boolean): Observable<PosteDetail> {
    return this.http.patch<{ data: PosteDetail }>(`${this.url}/${id}/statut`, { is_active: isActive }).pipe(map((r) => r.data));
  }
}
