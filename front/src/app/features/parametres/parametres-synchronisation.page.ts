import { Component, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { chevronBackOutline, syncOutline, cloudOfflineOutline, informationCircleOutline, checkmarkCircleOutline, alertCircleOutline, timeOutline, serverOutline, cloudUploadOutline } from 'ionicons/icons';
import { EtatSynchro, RejetSynchro, SynchroService } from '../../core/services/synchro.service';

/**
 * Parametres > Synchronisation (droit etablissement.gerer) : etat des echanges
 * entre le serveur de l'ecole et le serveur en ligne, lancement immediat
 * (serveur de l'ecole) et saisies faites en ligne refusees a l'ecole.
 */
@Component({
  selector: 'app-parametres-synchronisation',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner],
  templateUrl: './parametres-synchronisation.page.html',
  styleUrls: ['./parametres.commun.scss', './parametres-synchronisation.page.scss'],
})
export class ParametresSynchronisationPage {
  private readonly service = inject(SynchroService);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly etat = signal<EtatSynchro | null>(null);
  readonly rejets = signal<RejetSynchro[]>([]);
  readonly chargement = signal(true);
  readonly erreur = signal(false);
  readonly lancement = signal(false);

  constructor() {
    addIcons({ chevronBackOutline, syncOutline, cloudOfflineOutline, informationCircleOutline, checkmarkCircleOutline, alertCircleOutline, timeOutline, serverOutline, cloudUploadOutline });
  }

  ionViewWillEnter(): void {
    this.charger();
  }

  charger(): void {
    this.chargement.set(true);
    this.erreur.set(false);
    this.service.etat().subscribe({
      next: (r) => {
        this.etat.set(r.data);
        this.chargement.set(false);
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
    this.service.tousLesRejets().subscribe({ next: (r) => this.rejets.set(r.data), error: () => undefined });
  }

  lancer(): void {
    this.lancement.set(true);
    this.service.lancer().subscribe({
      next: (r) => {
        this.lancement.set(false);
        void this.notifier(r.message);
        this.charger();
      },
      error: (e: HttpErrorResponse) => {
        this.lancement.set(false);
        void this.notifier(e.error?.message || 'Synchronisation impossible.', true);
        this.charger();
      },
    });
  }

  marquerVu(r: RejetSynchro): void {
    this.service.marquerVu(r.id).subscribe(() => this.rejets.update((l) => l.map((x) => (x.id === r.id ? { ...x, vu: true } : x))));
  }

  dateHeure(valeur: string | null): string {
    if (!valeur) return 'jamais';
    const d = new Date(valeur.replace(' ', 'T'));
    return isNaN(d.getTime()) ? valeur : d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 4000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
