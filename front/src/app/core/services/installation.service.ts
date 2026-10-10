import { Injectable, computed, signal } from '@angular/core';

/** Evenement « beforeinstallprompt » (Chrome, Edge, Samsung Internet). */
interface InvitationInstallation extends Event {
  prompt(): Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

declare global {
  interface Window {
    /** Capture tres tot dans index.html, avant le demarrage d'Angular. */
    __invitationInstallation?: InvitationInstallation;
  }
}

const CLE_MASQUEE = 'monde_edu_installation_masquee';
/** Apres « fermer », la proposition revient au bout de 14 jours. */
const DUREE_MASQUEE = 14 * 24 * 3600 * 1000;

export type Plateforme = 'ios' | 'android' | 'autre';

/**
 * Installation de l'application sur l'ecran d'accueil (PWA) :
 * - quand le navigateur le permet (Android / Chrome en https ou localhost),
 *   « Installer » ouvre sa fenetre d'installation ;
 * - sinon (iPhone, ou adresse http non securisee), on explique le geste a
 *   faire : menu du navigateur > « Ajouter a l'ecran d'accueil ».
 */
@Injectable({ providedIn: 'root' })
export class InstallationService {
  private readonly invitation = signal<InvitationInstallation | null>(window.__invitationInstallation ?? null);
  private readonly installee = signal(this.modeApplication());
  /** Demande explicite d'affichage (ex. depuis le profil), meme apres « fermer ». */
  readonly demandee = signal(false);
  readonly masquee = signal(this.lireMasquee());

  readonly plateforme: Plateforme = this.detecterPlateforme();
  readonly telephone = this.plateforme !== 'autre' || window.matchMedia('(pointer: coarse) and (max-width: 991px)').matches;
  /** http hors localhost : le navigateur ne propose pas l'installation automatique. */
  readonly adresseNonSecurisee = !window.isSecureContext;
  /**
   * Navigateur integre a une autre application (lien ouvert depuis WhatsApp,
   * Gmail, Facebook...) : il ne sait pas installer, il faut ouvrir le lien
   * dans Chrome (Android) ou Safari (iPhone).
   */
  readonly navigateurIntegre = /; wv\)|FBAN|FBAV|Instagram|WhatsApp|Line\/|Snapchat|GSA\/|FB_IAB|MicroMessenger/i.test(navigator.userAgent);
  /** Lien qui rouvre la page courante dans Chrome (Android). */
  readonly lienChrome = `intent://${location.host}${location.pathname}${location.search}#Intent;scheme=https;package=com.android.chrome;end`;

  /** Le navigateur sait installer lui-meme (fenetre native). */
  readonly installationDirecte = computed(() => !!this.invitation());
  readonly disponible = computed(() => this.telephone && !this.installee());
  readonly aProposer = computed(() => this.disponible() && (this.demandee() || !this.masquee()));

  constructor() {
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      this.invitation.set(e as InvitationInstallation);
    });
    window.addEventListener('appinstalled', () => {
      this.installee.set(true);
      this.invitation.set(null);
    });
    window.matchMedia('(display-mode: standalone)').addEventListener?.('change', () => this.installee.set(this.modeApplication()));
  }

  /** Lance l'installation native ; false si le navigateur ne la propose pas (geste manuel). */
  async installer(): Promise<boolean> {
    // Chrome ne debloque l'installation qu'apres un peu d'utilisation du site :
    // le clic compte, on attend quelques secondes que l'invitation arrive.
    const invitation = this.invitation() ?? (await this.attendreInvitation(4000));
    if (!invitation) {
      return false;
    }
    await invitation.prompt();
    const choix = await invitation.userChoice;
    // Une invitation ne sert qu'une fois.
    this.invitation.set(null);
    window.__invitationInstallation = undefined;
    if (choix.outcome === 'accepted') {
      this.installee.set(true);
    }
    return true;
  }

  private attendreInvitation(delai: number): Promise<InvitationInstallation | null> {
    if (this.navigateurIntegre || this.plateforme === 'ios') return Promise.resolve(null);
    return new Promise((resolve) => {
      const fin = setTimeout(() => {
        window.removeEventListener('beforeinstallprompt', recue);
        resolve(this.invitation());
      }, delai);
      const recue = (): void => {
        clearTimeout(fin);
        // Laisse le service enregistrer l'invitation (ecouteur du constructeur).
        setTimeout(() => resolve(this.invitation()));
      };
      window.addEventListener('beforeinstallprompt', recue, { once: true });
    });
  }

  afficher(): void {
    this.demandee.set(true);
  }

  fermer(): void {
    this.demandee.set(false);
    this.masquee.set(true);
    try {
      localStorage.setItem(CLE_MASQUEE, String(Date.now()));
    } catch {
      // Stockage indisponible (navigation privee) : masquee pour la session seulement.
    }
  }

  private lireMasquee(): boolean {
    try {
      const t = Number(localStorage.getItem(CLE_MASQUEE));
      return !!t && Date.now() - t < DUREE_MASQUEE;
    } catch {
      return false;
    }
  }

  private modeApplication(): boolean {
    return window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
  }

  private detecterPlateforme(): Plateforme {
    const ua = navigator.userAgent;
    if (/iPad|iPhone|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) {
      return 'ios';
    }
    return /Android/i.test(ua) ? 'android' : 'autre';
  }
}
