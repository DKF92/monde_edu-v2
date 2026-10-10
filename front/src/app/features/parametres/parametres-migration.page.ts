import { Component, ElementRef, computed, inject, signal, viewChild } from '@angular/core';
import { KeyValuePipe } from '@angular/common';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AlertController, ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  chevronBackOutline, cloudOfflineOutline, serverOutline, playOutline, warningOutline, checkmarkCircleOutline,
  alertCircleOutline, documentTextOutline, keyOutline, swapHorizontalOutline, logInOutline, informationCircleOutline,
} from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { DocumentService } from '../../core/services/document.service';
import { BaseV2, EtatMigration, MigrationV1Service, SuiviMigration } from '../../core/services/migration-v1.service';

/**
 * Parametres > Migration V1 (Super admin, serveur de l'ecole) : reprise des
 * donnees V1 dans une base videe completement, journal en temps reel,
 * resultats des controles, et choix de la base utilisee par l'application.
 */
@Component({
  selector: 'app-parametres-migration',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner, KeyValuePipe],
  templateUrl: './parametres-migration.page.html',
  styleUrls: ['./parametres.commun.scss', './parametres-migration.page.scss'],
})
export class ParametresMigrationPage {
  private readonly service = inject(MigrationV1Service);
  private readonly documents = inject(DocumentService);
  private readonly auth = inject(AuthService);
  private readonly alertes = inject(AlertController);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  private readonly journal = viewChild<ElementRef<HTMLElement>>('journal');

  readonly etat = signal<EtatMigration | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal(false);

  readonly cible = signal('migration_monde_edu');
  readonly essai = signal(false);
  readonly lancement = signal(false);
  readonly erreurLancement = signal<string | null>(null);

  readonly suivi = signal<SuiviMigration | null>(null);
  private cle: string | null = null;
  private minuterie: ReturnType<typeof setInterval> | null = null;
  /** L'application a change de base : la session actuelle n'existe plus. */
  readonly basculee = signal(false);

  /** keyvalue sans tri : volumes dans l'ordre des etapes. */
  readonly sansTri = () => 0;

  /** Base choisie dans la liste horizontale (pour basculer). */
  readonly selection = signal<string | null>(null);
  readonly baseChoisie = computed(() => (this.etat()?.bases ?? []).find((b) => b.nom === this.selection()) ?? null);
  /** Raison pour laquelle on ne peut pas basculer sur la base choisie (null : possible). */
  readonly blocageBascule = computed(() => {
    const b = this.baseChoisie();
    if (!b) return 'Sélectionnez une base.';
    if (b.actuelle) return "C'est déjà la base utilisée.";
    if (b.vide) return "Base vide : lancez d'abord la migration.";
    if (!b.v2) return "Cette base n'a pas la structure de la V2.";
    if (!b.compte_present) return "Votre compte n'existe pas dans cette base.";
    if (this.enCours()) return 'Une migration est en cours.';
    return null;
  });

  choisir(b: BaseV2): void {
    this.selection.set(b.nom);
  }

  basculerSelection(): void {
    const b = this.baseChoisie();
    if (b && !this.blocageBascule()) void this.utiliser(b);
  }
  readonly enCours = computed(() => !!this.suivi() && !this.suivi()!.termine);
  readonly ciblePresente = computed(() => (this.etat()?.bases ?? []).find((b) => b.nom === this.cible()));
  readonly ecarts = computed(() => (this.suivi()?.resume?.controles ?? []).filter((c) => c.v1 !== c.v2).length);

  constructor() {
    addIcons({
      chevronBackOutline, cloudOfflineOutline, serverOutline, playOutline, warningOutline, checkmarkCircleOutline,
      alertCircleOutline, documentTextOutline, keyOutline, swapHorizontalOutline, logInOutline, informationCircleOutline,
    });
  }

  ionViewWillEnter(): void {
    this.charger();
  }

  ionViewWillLeave(): void {
    this.arreterSuivi();
  }

  charger(): void {
    if (this.basculee()) return;
    this.chargement.set(true);
    this.erreur.set(false);
    this.service.etat().subscribe({
      next: (r) => {
        this.etat.set(r.data);
        if (!this.selection() || !r.data.bases.some((b) => b.nom === this.selection())) this.selection.set(r.data.base_actuelle);
        this.chargement.set(false);
        if (!this.suivi() && r.data.cible_defaut) this.cible.set(this.cible() || r.data.cible_defaut);
        // Migration lancee avant d'ouvrir la page : on reprend son suivi.
        if (r.data.en_cours && this.cle !== r.data.en_cours.cle) this.suivre(r.data.en_cours.cle);
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
  }

  async lancer(): Promise<void> {
    const cible = this.cible().trim();
    const essai = this.essai();
    const alerte = await this.alertes.create({
      header: essai ? 'Lancer un essai ?' : 'Lancer la migration ?',
      message:
        `La base « ${cible} » sera vidée complètement puis recréée avec les données de la V1. ` +
        (essai
          ? 'Mode essai : la reprise est faite puis annulée, seul le rapport reste.'
          : "L'application reste sur la base actuelle ; à la fin, vous pourrez passer sur la base migrée."),
      buttons: [
        { text: 'Annuler', role: 'cancel' },
        { text: 'Lancer', role: 'confirm' },
      ],
    });
    await alerte.present();
    if ((await alerte.onDidDismiss()).role !== 'confirm') return;

    this.lancement.set(true);
    this.erreurLancement.set(null);
    this.service.lancer(cible, essai, false).subscribe({
      next: (r) => {
        this.lancement.set(false);
        this.suivi.set({ cible, essai, debut: r.data.debut, lignes: [], termine: false, succes: false, activee: false, resume: null });
        this.suivre(r.data.cle);
      },
      error: (e: HttpErrorResponse) => {
        this.lancement.set(false);
        this.erreurLancement.set(e.error?.errors?.cible?.[0] || e.error?.message || 'Lancement impossible.');
      },
    });
  }

  private suivre(cle: string): void {
    this.arreterSuivi();
    this.cle = cle;
    const lire = () =>
      this.service.suivi(cle).subscribe({
        next: (r) => {
          const avant = this.suivi()?.lignes.length ?? 0;
          this.suivi.set(r.data);
          if (r.data.lignes.length !== avant) setTimeout(() => this.defilerEnBas());
          if (r.data.termine) {
            this.arreterSuivi();
            if (r.data.activee) this.basculee.set(true);
            else this.charger();
          }
        },
        error: () => undefined,
      });
    lire();
    this.minuterie = setInterval(lire, 1500);
  }

  private arreterSuivi(): void {
    if (this.minuterie) clearInterval(this.minuterie);
    this.minuterie = null;
  }

  private defilerEnBas(): void {
    const el = this.journal()?.nativeElement;
    if (el) el.scrollTop = el.scrollHeight;
  }

  /** Fin de reprise : passer sur la base qui vient d'etre migree. */
  passerSurMigree(nom: string): void {
    void this.utiliser({ nom } as BaseV2);
  }

  async utiliser(base: BaseV2): Promise<void> {
    const alerte = await this.alertes.create({
      header: 'Changer de base ?',
      message: `L'application utilisera la base « ${base.nom} » pour tout le monde. Vous serez déconnecté immédiatement : reconnectez-vous avec le même e-mail et le même mot de passe.`,
      buttons: [
        { text: 'Annuler', role: 'cancel' },
        { text: 'Utiliser cette base', role: 'confirm' },
      ],
    });
    await alerte.present();
    if ((await alerte.onDidDismiss()).role !== 'confirm') return;
    this.service.basculer(base.nom).subscribe({
      next: (r) => {
        this.seReconnecter();
        void this.notifier(r.message);
      },
      error: (e: HttpErrorResponse) => void this.notifier(e.error?.message || 'Changement de base impossible.', true),
    });
  }

  seReconnecter(): void {
    this.arreterSuivi();
    this.auth.logout();
    void this.router.navigateByUrl('/login');
  }

  telecharger(nom: string): void {
    this.service.fichier(nom).subscribe({
      next: (r) => r.body && this.documents.enregistrer(r.body, nom),
      error: () => void this.notifier('Fichier introuvable.', true),
    });
  }

  nombre(v: number | string): string {
    return typeof v === 'number' ? v.toLocaleString('fr-FR') : v;
  }

  taille(octets: number): string {
    return octets > 1024 * 1024 ? `${(octets / 1024 / 1024).toFixed(1)} Mo` : `${Math.ceil(octets / 1024)} Ko`;
  }

  dateHeure(valeur: string | null): string {
    if (!valeur) return '';
    const d = new Date(valeur.replace(' ', 'T'));
    return isNaN(d.getTime()) ? valeur : d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  valeurTexte(e: Event): string {
    return (e.target as HTMLInputElement).value;
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 5000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
