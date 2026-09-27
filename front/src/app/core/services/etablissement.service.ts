import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { Etablissement } from '../models/user.model';

@Injectable({ providedIn: 'root' })
export class EtablissementService {
  constructor(private http: HttpClient) {}

  /** Annuaire public (pas besoin d'etre connecte) pour l'ecran de choix avant connexion. */
  listerPublic(recherche = ''): Observable<Etablissement[]> {
    return this.http
      .get<{ data: Etablissement[] }>(`${environment.apiUrl}/etablissements`, {
        params: recherche ? { recherche } : {},
      })
      .pipe(map((reponse) => reponse.data));
  }
}
