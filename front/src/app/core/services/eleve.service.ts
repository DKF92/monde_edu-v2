import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { Eleve, PageResultat } from '../models/eleve.model';

@Injectable({ providedIn: 'root' })
export class EleveService {
  constructor(private http: HttpClient) {}

  liste(recherche = '', page = 1): Observable<PageResultat<Eleve>> {
    return this.http.get<PageResultat<Eleve>>(`${environment.apiUrl}/eleves`, {
      params: { recherche, page },
    });
  }
}
