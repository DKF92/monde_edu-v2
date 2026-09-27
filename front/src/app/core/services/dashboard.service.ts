import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { StatsDashboard } from '../models/eleve.model';

@Injectable({ providedIn: 'root' })
export class DashboardService {
  constructor(private http: HttpClient) {}

  stats(): Observable<StatsDashboard> {
    return this.http.get<StatsDashboard>(`${environment.apiUrl}/dashboard`);
  }
}
