import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { chevronBackOutline, calendarOutline, schoolOutline, cloudOfflineOutline, informationCircleOutline } from 'ionicons/icons';
import { GrilleConduite, PageAbsences, PedagogieService } from '../../core/services/pedagogie.service';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';

/**
 * Notes de conduite /20 (V1 EDUCATEUR enregistrer_cond) d'une classe pour une
 * periode ; comptee coefficient 1 dans la moyenne, sanctions sous 14.
 */
@Component({
  selector: 'app-conduite',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner, SelecteurComponent],
  templateUrl: './conduite.page.html',
  styleUrl: '../evaluations/evaluations.scss',
})
export class ConduitePage {
  private readonly service = inject(PedagogieService);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly options = signal<Pick<PageAbsences, 'periodes' | 'classes'> | null>(null);
  readonly periodeId = signal(0);
  readonly classeId = signal(0);
  readonly grille = signal<GrilleConduite | null>(null);
  readonly chargement = signal(false);
  readonly erreur = signal(false);
  readonly valeurs = signal<Partial<Record<number, string>>>({});
  readonly enregistrement = signal(false);

  readonly optionsPeriode = computed<OptionSelecteur[]>(() => (this.options()?.periodes ?? []).map((p) => ({ valeur: p.id, libelle: p.libelle })));
  readonly optionsClasse = computed<OptionSelecteur[]>(() => (this.options()?.classes ?? []).map((c) => ({ valeur: c.id, libelle: c.libelle })));
  readonly invalides = computed(() => new Set(Object.entries(this.valeurs()).filter(([, v]) => (v ?? '').trim() !== '' && !this.valide(v ?? '')).map(([id]) => Number(id))));
  readonly modifie = computed(() => {
    const g = this.grille();
    return !!g && g.eleves.some((e) => (this.valeurs()[e.eleve_id] ?? '').trim() !== (e.note === null ? '' : String(e.note)));
  });

  constructor() {
    addIcons({ chevronBackOutline, calendarOutline, schoolOutline, cloudOfflineOutline, informationCircleOutline });
  }

  ionViewWillEnter(): void {
    this.service.absences({ par_page: 1 }).subscribe({
      next: (r) => {
        this.options.set({ periodes: r.periodes, classes: r.classes });
        if (!r.periodes.some((p) => p.id === this.periodeId())) this.periodeId.set((r.periodes.find((p) => p.active) ?? r.periodes[0])?.id ?? 0);
        if (!r.classes.some((c) => c.id === this.classeId())) this.classeId.set(r.classes[0]?.id ?? 0);
        this.charger();
      },
      error: () => this.erreur.set(true),
    });
  }

  choisirPeriode(id: number): void {
    this.periodeId.set(id);
    this.charger();
  }

  choisirClasse(id: number): void {
    this.classeId.set(id);
    this.charger();
  }

  charger(): void {
    if (!this.periodeId() || !this.classeId()) return;
    this.chargement.set(true);
    this.erreur.set(false);
    this.service.conduite(this.classeId(), this.periodeId()).subscribe({
      next: (g) => this.appliquer(g),
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
  }

  saisir(id: number, v: string): void {
    this.valeurs.update((x) => ({ ...x, [id]: v.replace(',', '.') }));
  }

  suivant(evenement: Event, index: number): void {
    evenement.preventDefault();
    (document.getElementById(`cond-${index + 1}`) as HTMLInputElement | null)?.focus();
  }

  enregistrer(): void {
    const g = this.grille();
    if (!g || this.invalides().size) return;
    this.enregistrement.set(true);
    this.service
      .enregistrerConduite(g.classe.id, g.periode.id, g.eleves.map((e) => ({ eleve_id: e.eleve_id, valeur: this.valeurs()[e.eleve_id]?.trim() ? Number(this.valeurs()[e.eleve_id]) : null })))
      .subscribe({
        next: (r) => {
          this.enregistrement.set(false);
          this.appliquer(r);
          this.notifier('Notes de conduite enregistrées.');
        },
        error: (e: HttpErrorResponse) => {
          this.enregistrement.set(false);
          const erreurs = e.error?.errors as Record<string, string[]> | undefined;
          this.notifier((erreurs && Object.values(erreurs)[0]?.[0]) || e.error?.message || 'Enregistrement impossible.', true);
        },
      });
  }

  annuler(): void {
    const g = this.grille();
    if (g) this.appliquer(g);
  }

  h(v: number): string {
    return v.toLocaleString('fr-FR', { maximumFractionDigits: 1 });
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  private appliquer(g: GrilleConduite): void {
    this.grille.set(g);
    this.valeurs.set(Object.fromEntries(g.eleves.map((e) => [e.eleve_id, e.note === null ? '' : String(e.note)])));
    this.chargement.set(false);
  }

  private valide(v: string): boolean {
    const n = Number(v);
    return Number.isFinite(n) && n >= 0 && n <= 20;
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
