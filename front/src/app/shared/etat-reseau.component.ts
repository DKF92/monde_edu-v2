import { Component, computed, inject } from '@angular/core';
import { Router } from '@angular/router';
import { IonIcon, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { cloudOfflineOutline, cloudUploadOutline, alertCircleOutline } from 'ionicons/icons';
import { ReseauService } from '../core/services/reseau.service';
import { FileAttenteService } from '../core/services/file-attente.service';
import { AuthService } from '../core/services/auth.service';
import { SynchroService } from '../core/services/synchro.service';

/**
 * Pastille d'etat du reseau, en haut de l'ecran, seulement quand il y a
 * quelque chose a savoir : hors ligne, saisies en attente ou refusees.
 * Un clic ouvre le Profil (detail, choix du serveur).
 */
@Component({
  selector: 'app-etat-reseau',
  standalone: true,
  imports: [IonIcon, IonSpinner],
  template: `
    @if (message(); as m) {
      <button type="button" class="pastille-reseau" [class]="'pastille-reseau ' + m.ton" (click)="ouvrir()" role="status" aria-live="polite">
        @if (m.ton === 'envoi') { <ion-spinner name="crescent"></ion-spinner> } @else { <ion-icon [name]="m.icone"></ion-icon> }
        <span>{{ m.texte }}</span>
      </button>
    }
  `,
  styles: `
    .pastille-reseau {
      position: fixed;
      z-index: 950; /* sous les fenetres Ionic */
      top: calc(8px + env(safe-area-inset-top));
      left: 50%;
      transform: translateX(-50%);
      display: flex;
      align-items: center;
      gap: 8px;
      max-width: calc(100vw - 24px);
      padding: 7px 14px;
      border: none;
      border-radius: 999px;
      box-shadow: 0 6px 18px rgba(20, 35, 70, 0.2);
      font: inherit;
      font-size: 12.5px;
      font-weight: 600;
      line-height: 1.3;
      text-align: left;
      cursor: pointer;
      ion-icon { font-size: 17px; flex-shrink: 0; }
      ion-spinner { width: 16px; height: 16px; flex-shrink: 0; }
    }
    .hors-ligne { background: #3d4456; color: #fff; }
    .envoi, .attente { background: var(--me-bleu-fond, #eef3ff); color: var(--me-marine, #1b3a6b); }
    .refus { background: var(--me-orange-fond, #fff4e6); color: #9a4b00; }
  `,
})
export class EtatReseauComponent {
  private readonly reseau = inject(ReseauService);
  private readonly file = inject(FileAttenteService);
  private readonly auth = inject(AuthService);
  private readonly synchro = inject(SynchroService);
  private readonly router = inject(Router);

  readonly message = computed<{ texte: string; ton: string; icone: string } | null>(() => {
    const n = this.file.enAttente();
    const attente = n ? ` · ${n} saisie${n > 1 ? 's' : ''} en attente` : '';
    if (this.reseau.horsLigne()) {
      return this.auth.estConnecte()
        ? { texte: `Hors ligne : derniers éléments consultés${attente}`, ton: 'hors-ligne', icone: 'cloud-offline-outline' }
        : { texte: 'Application indisponible : vous n\'êtes connecté à aucun réseau', ton: 'hors-ligne', icone: 'cloud-offline-outline' };
    }
    if (this.file.envoi()) return { texte: `Envoi des saisies hors ligne…`, ton: 'envoi', icone: '' };
    if (n) return { texte: `${n} saisie${n > 1 ? 's' : ''} en attente d'envoi`, ton: 'attente', icone: 'cloud-upload-outline' };
    // Refus : saisies hors ligne refusees par le serveur, et saisies faites
    // en ligne refusees a l'ecole a la synchronisation.
    const r = this.file.rejetees().length + this.synchro.rejets().length;
    if (r) return { texte: `${r} saisie${r > 1 ? "s" : ""} refusée${r > 1 ? "s" : ""} : voir le détail`, ton: 'refus', icone: 'alert-circle-outline' };
    return null;
  });

  constructor() {
    addIcons({ cloudOfflineOutline, cloudUploadOutline, alertCircleOutline });
  }

  ouvrir(): void {
    if (this.auth.estConnecte()) this.router.navigateByUrl('/tabs/profil');
    else void this.reseau.verifier();
  }
}
