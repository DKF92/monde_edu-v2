import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { AnneeScolaire } from '../models/user.model';

@Injectable({ providedIn: 'root' })
export class AnneeScolaireService {
  constructor(private http: HttpClient) {}

  lister(): Observable<AnneeScolaire[]> {
    return this.http
      .get<{ data: AnneeScolaire[] }>(`${environment.apiUrl}/annees-scolaires`)
      .pipe(map((reponse) => reponse.data));
  }
}
