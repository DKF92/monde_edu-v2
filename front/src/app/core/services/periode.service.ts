import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { Periode } from '../models/user.model';

@Injectable({ providedIn: 'root' })
export class PeriodeService {
  constructor(private http: HttpClient) {}

  lister(anneeScolaireId: number): Observable<Periode[]> {
    return this.http
      .get<{ data: Periode[] }>(`${environment.apiUrl}/periodes`, {
        params: { annee_scolaire_id: anneeScolaireId },
      })
      .pipe(map((reponse) => reponse.data));
  }
}
