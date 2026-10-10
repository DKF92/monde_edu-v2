import { Component, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AlertController, ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  chevronBackOutline, cloudOfflineOutline, globeOutline, searchOutline, createOutline, trashOutline,
  checkmarkCircleOutline, timeOutline, arrowForwardOutline, chevronForwardOutline, chevronBackCircleOutline,
} from 'ionicons/icons';
import { InscriptionService } from '../../core/services/inscription.service';
import { PaginationComponent, TAILLES_PAGE } from '../../shared/pagination.component';
import { LIBELLES_STATUT, MiseAJourEnLigne, PageMisesAJourEnLigne, TONS_STATUT } from '../../core/models/inscription.model';

/**
 * Inscriptions > Mises a jour en ligne : ce que « Mettre a jour » a repris du
 * site de l'Etat, element par element. Les elements en attente (niveau,
 * statut affecte) se traitent ici :
 * - « Modifier » (educateur, inscription au statut 1) : ouvre la modification
 *   de l'inscription avec la valeur en ligne ;
 * - « Supprimer les paiements » (caissier, statut > 1) : l'inscription
 *   repasse au statut 1, puis l'educateur la modifie.
 * Les autres postes consultent seulement.
 */
@Component({
  selector: 'app-mises-a-jour-en-ligne',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner, PaginationComponent],
  templateUrl: './mises-a-jour-en-ligne.page.html',
  styleUrl: './mises-a-jour-en-ligne.page.scss',
})
export class MisesAJourEnLignePage {
  private readonly service = inject(InscriptionService);
  private readonly alertes = inject(AlertController);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly libellesStatut = LIBELLES_STATUT;
  readonly tons = TONS_STATUT;

  readonly etat = signal<'a_traiter' | 'introuvables' | 'toutes'>('a_traiter');
  readonly recherche = signal('');
  readonly page = signal(1);
  /** 10 par page par defaut, au choix (10, 20, 50). */
  readonly taille = signal(TAILLES_PAGE[0]);
  readonly resultat = signal<PageMisesAJourEnLigne | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal(false);
  readonly action = signal<number | null>(null);
  private minuterieRecherche: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    addIcons({
      chevronBackOutline, cloudOfflineOutline, globeOutline, searchOutline, createOutline, trashOutline,
      checkmarkCircleOutline, timeOutline, arrowForwardOutline, chevronForwardOutline, chevronBackCircleOutline,
    });
  }

  ionViewWillEnter(): void {
    this.charger();
  }

  charger(): void {
    this.chargement.set(true);
    this.erreur.set(false);
    this.service.misesAJour({ etat: this.etat(), recherche: this.recherche(), page: this.page(), par_page: this.taille() }).subscribe({
      next: (r) => {
        this.resultat.set(r);
        this.chargement.set(false);
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
  }

  choisirEtat(etat: 'a_traiter' | 'introuvables' | 'toutes'): void {
    this.etat.set(etat);
    this.page.set(1);
    this.charger();
  }

  chercher(e: Event): void {
    this.recherche.set((e.target as HTMLInputElement).value);
    if (this.minuterieRecherche) clearTimeout(this.minuterieRecherche);
    this.minuterieRecherche = setTimeout(() => {
      this.page.set(1);
      this.charger();
    }, 350);
  }

  allerPage(page: number): void {
    this.page.set(page);
    this.charger();
  }

  changerTaille(taille: number): void {
    this.taille.set(taille);
    this.page.set(1);
    this.charger();
  }

  ouvrir(m: MiseAJourEnLigne): void {
    if (m.inscription_id) void this.router.navigate(['/tabs/inscriptions', m.inscription_id]);
  }

  /** Educateur : modification de l'inscription avec les valeurs en ligne en attente. */
  modifier(m: MiseAJourEnLigne): void {
    if (!m.inscription_id) return;
    void this.router.navigate(['/tabs/inscriptions', m.inscription_id, 'modifier'], {
      state: {
        miseAJour: {
          id: m.id,
          niveau_id: m.niveau_demande_id,
          affecte: m.affecte_demande,
          changements: m.changements.filter((c) => !c.applique && !c.traite).map((c) => `${c.libelle} : ${c.apres}`),
        },
      },
    });
  }

  /** Caissier : supprime les paiements (motif obligatoire) pour que l'inscription repasse au statut 1. */
  async supprimerPaiements(m: MiseAJourEnLigne): Promise<void> {
    const alerte = await this.alertes.create({
      header: 'Supprimer les paiements ?',
      message: `Tous les paiements de ${m.eleve?.nom ?? ''} ${m.eleve?.prenoms ?? ''} pour cette année seront supprimés (historisés). L'inscription repassera au statut « ${LIBELLES_STATUT[1]} » et l'éducateur pourra reporter les changements en ligne.`,
      inputs: [{ name: 'motif', type: 'textarea', placeholder: 'Motif (obligatoire)' }],
      buttons: [
        { text: 'Annuler', role: 'cancel' },
        { text: 'Supprimer', role: 'confirm', cssClass: 'danger' },
      ],
    });
    await alerte.present();
    const { role, data } = await alerte.onDidDismiss();
    if (role !== 'confirm') return;
    const motif = (data?.values?.motif ?? '').trim();
    if (motif.length < 3) {
      void this.notifier('Précisez le motif de la suppression (3 caractères au moins).', true);
      return;
    }
    this.action.set(m.id);
    this.service.supprimerPaiementsMiseAJour(m.id, motif).subscribe({
      next: (r) => {
        this.action.set(null);
        void this.notifier(r.message);
        this.charger();
      },
      error: (e: HttpErrorResponse) => {
        this.action.set(null);
        void this.notifier(e.error?.errors?.motif?.[0] || e.error?.message || 'Suppression impossible.', true);
      },
    });
  }

  valeur(v: string | number | null): string {
    return v === null || v === '' ? '—' : String(v);
  }

  dateHeure(valeur: string | null): string {
    if (!valeur) return '';
    const d = new Date(valeur.replace(' ', 'T'));
    return isNaN(d.getTime()) ? valeur : d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 4500, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
