import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { chevronBackOutline, cloudOfflineOutline, walletOutline, addOutline, removeOutline, informationCircleOutline } from 'ionicons/icons';
import { ParametreService } from '../../core/services/parametre.service';
import { ParametresDettes } from '../../core/models/parametre.model';

/**
 * Parametres > Dettes (droit tarifs.gerer) : sur combien d'annees en arriere
 * une dette est prise en compte (caisse, reste a payer, liste et point des
 * dettes, reductions). 1 = seulement le reste de l'annee precedente (n-1).
 */
@Component({
  selector: 'app-parametres-dettes',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner],
  templateUrl: './parametres-dettes.page.html',
  styleUrls: ['./parametres.commun.scss', './parametres-dettes.page.scss'],
})
export class ParametresDettesPage {
  private readonly service = inject(ParametreService);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly donnees = signal<ParametresDettes | null>(null);
  readonly nombre = signal(1);
  readonly chargement = signal(true);
  readonly erreur = signal(false);
  readonly enregistrement = signal(false);

  readonly modifie = computed(() => !!this.donnees() && this.nombre() !== this.donnees()!.dettes_annees);

  /** Annees concernees par le nombre saisi (a partir de l'annee de travail). */
  readonly anneesConcernees = computed(() => {
    const annee = this.donnees()?.annee;
    const debut = annee ? parseInt(annee.slice(0, 4), 10) : NaN;
    if (isNaN(debut)) return [];
    return Array.from({ length: this.nombre() }, (_, i) => `${debut - 1 - i}-${debut - i}`);
  });

  constructor() {
    addIcons({ chevronBackOutline, cloudOfflineOutline, walletOutline, addOutline, removeOutline, informationCircleOutline });
  }

  ionViewWillEnter(): void {
    this.charger();
  }

  charger(): void {
    this.chargement.set(true);
    this.erreur.set(false);
    this.service.dettes().subscribe({
      next: (d) => {
        this.donnees.set(d);
        this.nombre.set(d.dettes_annees);
        this.chargement.set(false);
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
  }

  changer(delta: number): void {
    this.nombre.set(Math.min(20, Math.max(1, this.nombre() + delta)));
  }

  saisir(e: Event): void {
    const v = parseInt((e.target as HTMLInputElement).value, 10);
    if (!isNaN(v)) this.nombre.set(Math.min(20, Math.max(1, v)));
  }

  annuler(): void {
    this.nombre.set(this.donnees()?.dettes_annees ?? 1);
  }

  enregistrer(): void {
    this.enregistrement.set(true);
    this.service.enregistrerDettes(this.nombre()).subscribe({
      next: (d) => {
        this.donnees.set(d);
        this.nombre.set(d.dettes_annees);
        this.enregistrement.set(false);
        void this.notifier('Paramètre des dettes enregistré.');
      },
      error: (e: HttpErrorResponse) => {
        this.enregistrement.set(false);
        void this.notifier(e.error?.errors?.dettes_annees?.[0] || e.error?.message || 'Enregistrement impossible.', true);
      },
    });
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 4000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
