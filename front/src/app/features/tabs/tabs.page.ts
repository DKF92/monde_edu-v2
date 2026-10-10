import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { NavigationEnd, NavigationStart, Router, RouterLink, RouterLinkActive } from '@angular/router';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { ScanEnLigneComponent } from '../../shared/scan-en-ligne.component';
import { filter, map } from 'rxjs';
import {
  IonSplitPane,
  IonMenu,
  IonMenuToggle,
  IonRouterOutlet,
  IonIcon,
  IonPopover,
  IonContent,
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  searchOutline,
  notificationsOutline,
  chatbubblesOutline,
  calendarOutline,
  chevronDownOutline,
  chevronBackOutline,
  chevronForwardOutline,
  closeOutline,
  menuOutline,
  logOutOutline,
  businessOutline,
  swapHorizontalOutline,
  briefcaseOutline,
  notificationsOffOutline,
  gridOutline,
} from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { NavigationService } from '../../core/services/navigation.service';
import { ENTREE_PROFIL, ICONES_NAVIGATION } from '../../core/navigation';
import { LogoComponent } from '../../shared/logo.component';
import { EspaceTravailService } from '../../core/services/espace-travail.service';
import { ChoisirContexteModalComponent } from '../contexte/choisir-contexte-modal.component';
import { ContexteService } from '../../core/services/contexte.service';
import { AssistantComponent } from '../assistant/assistant.component';

const STORAGE_MENU_REDUIT = 'monde_edu_menu_reduit';

/**
 * Coquille de l'application une fois connecte :
 * - bureau (>= 992px) : menu lateral fixe (reductible) + barre du haut
 *   (recherche, annee/trimestre, notifications, compte) ;
 * - mobile : barre d'onglets en bas + menu coulissant (bouton "Menu").
 *
 */
@Component({
  selector: 'app-tabs',
  standalone: true,
  imports: [
    ScanEnLigneComponent,
    FormsModule,
    RouterLink,
    RouterLinkActive,
    IonSplitPane,
    IonMenu,
    IonMenuToggle,
    IonRouterOutlet,
    IonIcon,
    IonPopover,
    IonContent,
    LogoComponent,
    ChoisirContexteModalComponent,
    AssistantComponent,
  ],
  templateUrl: './tabs.page.html',
  styleUrl: './tabs.page.scss',
})
export class TabsPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly navigation = inject(NavigationService);
  private readonly contextes = inject(ContexteService);
  readonly espaceTravail = inject(EspaceTravailService);

  readonly sections = this.navigation.sections;

  /** Adresse courante, pour le lien actif du menu. */
  private readonly url = toSignal(
    this.router.events.pipe(
      filter((e): e is NavigationEnd => e instanceof NavigationEnd),
      map((e) => e.urlAfterRedirects),
    ),
    { initialValue: this.router.url },
  );

  /**
   * Lien actif : l'adresse commence par sa route, sauf si une autre entree
   * plus precise correspond (Caisse /tabs/paiements vs Paiements /tabs/paiements/liste).
   */
  estActif(route: string): boolean {
    // Detail / modification d'un paiement : rattache a la liste des paiements.
    const url = this.url().split('?')[0].replace(/^\/tabs\/paiements\/\d+(\/.*)?$/, '/tabs/paiements/liste');
    const correspond = (r: string) => url === r || url.startsWith(r + '/');
    if (!correspond(route)) {
      return false;
    }
    return !this.sections().some((s) => s.entrees.some((e) => e.route.length > route.length && e.route.startsWith(route + '/') && correspond(e.route)));
  }
  readonly ongletsMobile = computed(() => [...this.navigation.barreMobile(), ENTREE_PROFIL]);
  readonly entreeProfil = ENTREE_PROFIL;

  readonly etablissement = this.auth.etablissementActif;
  readonly anneeScolaire = this.auth.anneeScolaire;
  readonly periode = this.auth.periode;
  readonly poste = this.auth.poste;
  readonly nomComplet = this.auth.nomComplet;
  readonly initiales = this.auth.initiales;
  readonly role = this.auth.role;
  readonly peutRechercherEleves = computed(() => {
    this.auth.user();
    return this.auth.aPermission('eleves.voir');
  });

  // Modale d'espace de travail : bloquante tant qu'il n'est pas choisi, ou
  // ouverte a la demande pour en changer. Conditionne a la connexion : Ionic
  // garde cette page en cache apres une deconnexion.
  readonly contexteManquant = computed(() => this.auth.estConnecte() && !this.auth.contexteComplet());
  // Mot de passe provisoire : l'ecran de choix du mot de passe passe avant.
  readonly modaleOuverte = computed(
    () =>
      this.auth.estConnecte() &&
      !this.auth.doitChangerMotDePasse() &&
      (this.contexteManquant() || this.espaceTravail.changementDemande()),
  );

  readonly menuReduit = signal(localStorage.getItem(STORAGE_MENU_REDUIT) === '1');
  readonly recherche = signal('');


  constructor() {
    // Changement de page depuis une fenetre (ex. clic sur un eleve d'une
    // liste) : la fenetre de la page quittee est fermee proprement, sinon elle
    // resterait affichee par-dessus la nouvelle page. La fenetre d'espace de
    // travail garde sa propre logique.
    this.router.events.pipe(filter((e) => e instanceof NavigationStart), takeUntilDestroyed()).subscribe(() => {
      document.querySelectorAll<HTMLIonModalElement>('ion-modal.show-modal:not(.modale-contexte)').forEach((m) => m.dismiss().catch(() => undefined));
    });
    addIcons({
      ...ICONES_NAVIGATION,
      searchOutline,
      notificationsOutline,
      chatbubblesOutline,
      calendarOutline,
      chevronDownOutline,
      chevronBackOutline,
      chevronForwardOutline,
      closeOutline,
      menuOutline,
      logOutOutline,
      businessOutline,
      swapHorizontalOutline,
      briefcaseOutline,
      notificationsOffOutline,
      gridOutline,
    });

    // Droits du poste rafraichis a l'ouverture et au retour sur l'application.
    this.rafraichirDroits();
    const auRetour = () => document.visibilityState === 'visible' && this.rafraichirDroits();
    document.addEventListener('visibilitychange', auRetour);
    inject(DestroyRef).onDestroy(() => document.removeEventListener('visibilitychange', auRetour));
  }

  private rafraichirDroits(): void {
    const etablissement = this.auth.etablissementActif();
    const poste = this.auth.poste();
    if (!etablissement || !poste) return;
    // Hors ligne ou erreur : on garde les droits connus.
    this.contextes.charger(etablissement.id).subscribe({
      next: (c) => {
        const aJour = c.postes.find((p) => p.id === poste.id);
        if (aJour) this.auth.rafraichirPoste(aJour);
      },
      error: () => undefined,
    });
  }

  basculerMenu(): void {
    this.menuReduit.update((reduit) => !reduit);
    localStorage.setItem(STORAGE_MENU_REDUIT, this.menuReduit() ? '1' : '0');
  }

  rechercher(): void {
    const recherche = this.recherche().trim();
    this.router.navigate(['/tabs/eleves'], { queryParams: recherche ? { recherche } : {} });
  }

  changerEspace(): void {
    this.espaceTravail.ouvrir();
  }

  seDeconnecter(): void {
    this.auth.logout();
    this.router.navigateByUrl('/login');
  }
}
