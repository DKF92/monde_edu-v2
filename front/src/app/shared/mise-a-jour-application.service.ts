import { ApplicationRef, Injectable, inject } from '@angular/core';
import { SwUpdate, VersionReadyEvent } from '@angular/service-worker';
import { ToastController } from '@ionic/angular';
import { filter, first } from 'rxjs';

/**
 * Application installee (PWA) : sans ce service, le telephone garde l'ancienne
 * version en cache jusqu'au 2e redemarrage. On cherche une nouvelle version au
 * demarrage puis toutes les 15 minutes ; des qu'elle est prete, un bandeau
 * propose de recharger.
 */
@Injectable({ providedIn: 'root' })
export class MiseAJourApplicationService {
  private readonly sw = inject(SwUpdate);
  private readonly appli = inject(ApplicationRef);
  private readonly toasts = inject(ToastController);

  demarrer(): void {
    if (!this.sw.isEnabled) return;
    this.sw.versionUpdates.pipe(filter((e): e is VersionReadyEvent => e.type === 'VERSION_READY')).subscribe(() => void this.proposer());
    this.sw.unrecoverable.subscribe(() => document.location.reload());
    this.appli.isStable.pipe(first((stable) => stable)).subscribe(() => {
      void this.sw.checkForUpdate().catch(() => undefined);
      setInterval(() => void this.sw.checkForUpdate().catch(() => undefined), 15 * 60 * 1000);
    });
  }

  private async proposer(): Promise<void> {
    const toast = await this.toasts.create({
      message: 'Une nouvelle version de Monde Éducatif est disponible.',
      position: 'bottom',
      color: 'dark',
      buttons: [{ text: 'Mettre à jour', handler: () => void this.sw.activateUpdate().then(() => document.location.reload()) }],
    });
    await toast.present();
  }
}
