import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { chevronBackOutline, printOutline, searchOutline, schoolOutline, trendingUpOutline, trendingDownOutline, walletOutline, cloudOfflineOutline, calendarOutline } from 'ionicons/icons';
import { DepenseService, FiltresPoint, PointCaisse } from '../../core/services/depense.service';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';

/**
 * Point de caisse (V1 depense.php + solde_caisse.php) : encaissements,
 * depenses et solde sur l'annee, un mois ou des dates ; detail par mois
 * (salaires / autres depenses) et par categorie ; imprimable.
 */
@Component({
  selector: 'app-point-depenses',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner, SelecteurComponent],
  templateUrl: './point-depenses.page.html',
  styleUrl: './point-depenses.page.scss',
})
export class PointDepensesPage {
  private readonly service = inject(DepenseService);
  readonly router = inject(Router);

  readonly point = signal<PointCaisse | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal<string | null>(null);
  readonly impression = signal(false);
  readonly filtres = signal<FiltresPoint>({ periode: 'annee' });
  readonly du = signal(this.aujourdhui());
  readonly au = signal(this.aujourdhui());

  readonly optionsMois = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Choisir un mois…' },
    ...(this.point()?.mois_disponibles ?? []).map((m, i) => ({ valeur: i + 1, libelle: m.libelle })),
  ]);
  readonly rangMois = computed(() => (this.filtres().periode === 'mois' ? (this.point()?.mois_disponibles ?? []).findIndex((m) => m.valeur === this.filtres().mois) + 1 : 0));
  readonly maxCategorie = computed(() => Math.max(1, ...(this.point()?.par_categorie ?? []).map((c) => c.montant)));

  constructor() {
    addIcons({ chevronBackOutline, printOutline, searchOutline, schoolOutline, trendingUpOutline, trendingDownOutline, walletOutline, cloudOfflineOutline, calendarOutline });
  }

  ionViewWillEnter(): void {
    this.charger();
  }

  charger(filtres = this.filtres()): void {
    this.chargement.set(true);
    this.erreur.set(null);
    this.service.point(filtres).subscribe({
      next: (p) => {
        this.filtres.set(filtres);
        this.point.set(p);
        this.chargement.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.chargement.set(false);
        const erreurs = e.error?.errors as Record<string, string[]> | undefined;
        this.erreur.set((erreurs && Object.values(erreurs)[0]?.[0]) || e.error?.message || 'Impossible de charger le point de caisse.');
      },
    });
  }

  annee(): void {
    this.charger({ periode: 'annee' });
  }

  choisirMois(rang: number): void {
    const m = this.point()?.mois_disponibles[rang - 1];
    if (m) this.charger({ periode: 'mois', mois: m.valeur });
  }

  afficherDates(): void {
    this.charger({ periode: 'dates', du: this.du(), au: this.au() || this.du() });
  }

  async imprimer(): Promise<void> {
    this.impression.set(true);
    await this.service.imprimerPoint(this.filtres(), this.point()?.titre ?? 'Point de caisse');
    this.impression.set(false);
  }

  f(v: number | null | undefined): string {
    return (v ?? 0).toLocaleString('fr-FR') + ' F';
  }

  largeur(montant: number): number {
    return Math.round((montant / this.maxCategorie()) * 100);
  }

  total(cle: 'salaires' | 'autres'): number {
    return (this.point()?.par_mois ?? []).reduce((s, l) => s + l[cle], 0);
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  private aujourdhui(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }
}
