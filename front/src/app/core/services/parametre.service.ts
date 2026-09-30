import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import {
  AnneeGestion,
  GrilleColonne,
  InfosEtablissement,
  InfosEtablissementSaisie,
  MessageSms,
  NouvelleAnnee,
  PageServeur,
  ParametresSms,
  RechargementSms,
  ParametresClasses,
  ParametresInscriptions,
  ParametresInscriptionsSaisie,
  TarifsNiveaux,
  TypeFrais,
  TypeReduction,
  TypeReductionSaisie,
} from '../models/parametre.model';

/** Parametres de l'etablissement : classes, inscriptions, paiements. */
@Injectable({ providedIn: 'root' })
export class ParametreService {
  private readonly http = inject(HttpClient);
  private readonly url = `${environment.apiUrl}/parametres`;

  classes(): Observable<ParametresClasses> {
    return this.http.get<ParametresClasses>(`${this.url}/classes`);
  }

  enregistrerClasses(saisie: {
    effectif_max_classe: number | null;
    numerotation_classes: 'chiffres' | 'lettres';
    niveaux: { id: number; effectif_max: number | null }[];
    classes: { id: number; capacite: number | null }[];
  }): Observable<ParametresClasses> {
    return this.http.put<ParametresClasses>(`${this.url}/classes`, saisie);
  }

  inscriptions(): Observable<ParametresInscriptions> {
    return this.http.get<ParametresInscriptions>(`${this.url}/inscriptions`);
  }

  enregistrerInscriptions(saisie: ParametresInscriptionsSaisie): Observable<ParametresInscriptions> {
    return this.http.put<ParametresInscriptions>(`${this.url}/inscriptions`, saisie);
  }

  tarifs(): Observable<TarifsNiveaux> {
    return this.http.get<TarifsNiveaux>(`${this.url}/paiements/tarifs`);
  }

  enregistrerTarif(
    niveauId: number,
    saisie: { affecte: Pick<GrilleColonne, 'montants'>; non_affecte: Pick<GrilleColonne, 'montants'> },
  ): Observable<TarifsNiveaux> {
    return this.http.put<TarifsNiveaux>(`${this.url}/paiements/tarifs/${niveauId}`, saisie);
  }

  creerTypeFrais(libelle: string, nature: 'annexe' | 'en_nature' = 'annexe'): Observable<TypeFrais> {
    return this.http.post<{ data: TypeFrais }>(`${this.url}/paiements/types-frais`, { libelle, nature }).pipe(map((r) => r.data));
  }

  modifierTypeFrais(
    id: number,
    modif: Partial<Pick<TypeFrais, 'libelle' | 'is_active' | 'applicable_affecte' | 'applicable_non_affecte'>>,
  ): Observable<TypeFrais> {
    return this.http.put<{ data: TypeFrais }>(`${this.url}/paiements/types-frais/${id}`, modif).pipe(map((r) => r.data));
  }

  /** Ordre de paiement : types de frais en argent, du premier au dernier paye. */
  ordonnerTypesFrais(ids: number[]): Observable<TypeFrais[]> {
    return this.http.put<{ data: TypeFrais[] }>(`${this.url}/paiements/types-frais/ordre`, { ids }).pipe(map((r) => r.data));
  }

  typesReductions(): Observable<TypeReduction[]> {
    return this.http.get<{ data: TypeReduction[] }>(`${this.url}/paiements/types-reductions`).pipe(map((r) => r.data));
  }

  creerTypeReduction(saisie: TypeReductionSaisie): Observable<TypeReduction> {
    return this.http.post<{ data: TypeReduction }>(`${this.url}/paiements/types-reductions`, saisie).pipe(map((r) => r.data));
  }

  modifierTypeReduction(id: number, saisie: TypeReductionSaisie): Observable<TypeReduction> {
    return this.http.put<{ data: TypeReduction }>(`${this.url}/paiements/types-reductions/${id}`, saisie).pipe(map((r) => r.data));
  }

  // ------------------------------------------------------------ Etablissement

  etablissement(): Observable<InfosEtablissement> {
    return this.http.get<{ data: InfosEtablissement }>(`${this.url}/etablissement`).pipe(map((r) => r.data));
  }

  enregistrerEtablissement(saisie: InfosEtablissementSaisie): Observable<InfosEtablissement> {
    return this.http.put<{ data: InfosEtablissement }>(`${this.url}/etablissement`, saisie).pipe(map((r) => r.data));
  }

  envoyerLogo(fichier: File): Observable<InfosEtablissement> {
    const donnees = new FormData();
    donnees.append('logo', fichier);
    return this.http.post<{ data: InfosEtablissement }>(`${this.url}/etablissement/logo`, donnees).pipe(map((r) => r.data));
  }

  supprimerLogo(): Observable<InfosEtablissement> {
    return this.http.delete<{ data: InfosEtablissement }>(`${this.url}/etablissement/logo`).pipe(map((r) => r.data));
  }

  // ------------------------------------------------------------ Annees scolaires

  annees(): Observable<AnneeGestion[]> {
    return this.http.get<{ data: AnneeGestion[] }>(`${this.url}/annees`).pipe(map((r) => r.data));
  }

  creerAnnee(saisie: NouvelleAnnee): Observable<AnneeGestion> {
    return this.http.post<{ data: AnneeGestion }>(`${this.url}/annees`, saisie).pipe(map((r) => r.data));
  }

  modifierAnnee(id: number, dates: { date_debut: string | null; date_fin: string | null }): Observable<AnneeGestion> {
    return this.http.put<{ data: AnneeGestion }>(`${this.url}/annees/${id}`, dates).pipe(map((r) => r.data));
  }

  activerAnnee(id: number): Observable<AnneeGestion[]> {
    return this.http.post<{ data: AnneeGestion[] }>(`${this.url}/annees/${id}/activer`, {}).pipe(map((r) => r.data));
  }

  cloturerAnnee(id: number, cloturee: boolean): Observable<AnneeGestion> {
    return this.http.patch<{ data: AnneeGestion }>(`${this.url}/annees/${id}/cloture`, { cloturee }).pipe(map((r) => r.data));
  }

  changerDecoupage(id: number, type: 'trimestre' | 'semestre'): Observable<AnneeGestion> {
    return this.http.patch<{ data: AnneeGestion }>(`${this.url}/annees/${id}/decoupage`, { type_decoupage: type }).pipe(map((r) => r.data));
  }

  modifierPeriode(id: number, dates: { date_debut: string | null; date_fin: string | null }): Observable<AnneeGestion> {
    return this.http.put<{ data: AnneeGestion }>(`${this.url}/periodes/${id}`, dates).pipe(map((r) => r.data));
  }

  activerPeriode(id: number): Observable<AnneeGestion> {
    return this.http.post<{ data: AnneeGestion }>(`${this.url}/periodes/${id}/activer`, {}).pipe(map((r) => r.data));
  }

  cloturerPeriode(id: number, cloturee: boolean): Observable<AnneeGestion> {
    return this.http.patch<{ data: AnneeGestion }>(`${this.url}/periodes/${id}/cloture`, { cloturee }).pipe(map((r) => r.data));
  }

  // ------------------------------------------------------------ SMS

  sms(): Observable<ParametresSms> {
    return this.http.get<{ data: ParametresSms }>(`${this.url}/sms`).pipe(map((r) => r.data));
  }

  enregistrerSms(saisie: { sms_actif: boolean; sms_expediteur: string | null }): Observable<ParametresSms> {
    return this.http.put<{ data: ParametresSms }>(`${this.url}/sms`, saisie).pipe(map((r) => r.data));
  }

  rechargementsSms(page: number, taille: number): Observable<PageServeur<RechargementSms>> {
    return this.http.get<PageServeur<RechargementSms>>(`${this.url}/sms/rechargements`, { params: { page, taille } });
  }

  rechargerSms(saisie: { nombre_sms: number; montant: number; commentaire: string | null }): Observable<ParametresSms> {
    return this.http.post<{ data: ParametresSms }>(`${this.url}/sms/rechargements`, saisie).pipe(map((r) => r.data));
  }

  messagesSms(page: number, taille: number, filtres: { recherche?: string; statut?: string | null }): Observable<PageServeur<MessageSms>> {
    const params: Record<string, string | number> = { page, taille };
    if (filtres.recherche?.trim()) {
      params['recherche'] = filtres.recherche.trim();
    }
    if (filtres.statut) {
      params['statut'] = filtres.statut;
    }
    return this.http.get<PageServeur<MessageSms>>(`${this.url}/sms/messages`, { params });
  }

  testerSms(numero: string, message: string): Observable<{ message: string; data: ParametresSms }> {
    return this.http.post<{ message: string; data: ParametresSms }>(`${this.url}/sms/test`, { numero, message });
  }
}
