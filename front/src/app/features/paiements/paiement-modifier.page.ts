import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { chevronBackOutline, saveOutline, cloudOfflineOutline, informationCircleOutline, createOutline } from 'ionicons/icons';
import { CaisseService } from '../../core/services/caisse.service';
import { DetailPaiement, LIBELLES_MODE, ModePaiement } from '../../core/models/caisse.model';

/**
 * Modification d'un paiement (droit reglements.modifier) : montant, dette,
 * mode, dates ; motif obligatoire (saisi avant d'arriver ici, modifiable).
 * L'API revalide avec les regles du versement et repartit a nouveau.
 */
@Component({
  selector: 'app-paiement-modifier',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner],
  templateUrl: './paiement-modifier.page.html',
  styleUrl: './paiement-modifier.page.scss',
})
export class PaiementModifierPage {
  private readonly caisse = inject(CaisseService);
  private readonly route = inject(ActivatedRoute);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly libellesMode = LIBELLES_MODE;
  readonly modes = Object.keys(LIBELLES_MODE) as ModePaiement[];

  readonly detail = signal<DetailPaiement | null>(null);
  readonly chargement = signal(true);
  readonly erreurChargement = signal<string | null>(null);
  readonly enregistrement = signal(false);
  readonly erreurs = signal<Record<string, string>>({});

  readonly motif = signal('');
  readonly montant = signal<number | null>(null);
  readonly montantDette = signal<number | null>(null);
  readonly mode = signal<ModePaiement>('especes');
  readonly datePaiement = signal('');
  readonly dateExpiration = signal<string | null>(null);

  readonly total = computed(() => (this.montant() ?? 0) + (this.montantDette() ?? 0));

  constructor() {
    addIcons({ chevronBackOutline, saveOutline, cloudOfflineOutline, informationCircleOutline, createOutline });
  }

  ionViewWillEnter(): void {
    this.motif.set((history.state?.motif as string | undefined) ?? '');
    this.chargement.set(true);
    this.caisse.detail(this.id()).subscribe({
      next: (d) => {
        this.detail.set(d);
        this.montant.set(d.modification?.montant ?? 0);
        this.montantDette.set(d.modification?.montant_dette ?? 0);
        this.mode.set(d.reglement.mode);
        this.datePaiement.set(d.reglement.date.slice(0, 10));
        this.dateExpiration.set(d.reglement.date_expiration);
        this.erreurs.set({});
        this.chargement.set(false);
      },
      error: () => {
        this.chargement.set(false);
        this.erreurChargement.set('Impossible de charger le paiement.');
      },
    });
  }

  enregistrer(): void {
    const erreurs: Record<string, string> = {};
    if (this.motif().trim().length < 3) erreurs['motif'] = 'Le motif est obligatoire (3 caractères au moins).';
    if (this.total() <= 0) erreurs['montant'] = 'Saisissez le montant versé.';
    this.erreurs.set(erreurs);
    if (Object.keys(erreurs).length || this.enregistrement()) {
      return;
    }
    this.enregistrement.set(true);
    this.caisse
      .modifier(this.id(), {
        motif: this.motif().trim(),
        montant: this.montant() ?? 0,
        montant_dette: this.montantDette() ?? 0,
        mode_paiement: this.mode(),
        date_paiement: this.datePaiement() || null,
        date_expiration: this.dateExpiration() || null,
      })
      .subscribe({
        next: async (r) => {
          this.enregistrement.set(false);
          const toast = await this.toasts.create({ message: `Paiement n° ${r.numero_recu} modifié.`, duration: 3000, color: 'dark' });
          await toast.present();
          this.router.navigate(['/tabs/paiements', r.id], { replaceUrl: true });
        },
        error: async (e: HttpErrorResponse) => {
          this.enregistrement.set(false);
          const brutes = (e.error?.errors ?? {}) as Record<string, string[]>;
          const liste = Object.fromEntries(Object.entries(brutes).map(([k, v]) => [k, v[0]]));
          this.erreurs.set(liste);
          const toast = await this.toasts.create({ message: Object.values(liste)[0] || e.error?.message || 'Modification impossible.', duration: 3500, color: 'danger' });
          await toast.present();
        },
      });
  }

  f(v: number | null | undefined): string {
    return (v ?? 0).toLocaleString('fr-FR') + ' F';
  }

  valeurNombre(evenement: Event): number | null {
    const brut = (evenement.target as HTMLInputElement).value.replace(/\s/g, '');
    return brut === '' || isNaN(Number(brut)) ? null : Math.round(Number(brut));
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  private id(): number {
    return Number(this.route.snapshot.paramMap.get('id'));
  }
}
