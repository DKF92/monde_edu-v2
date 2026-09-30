import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { ToastController, IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  chevronBackOutline,
  chatbubblesOutline,
  walletOutline,
  sendOutline,
  settingsOutline,
  addOutline,
  closeOutline,
  searchOutline,
  warningOutline,
  informationCircleOutline,
  cloudOfflineOutline,
  checkmarkCircle,
  closeCircle,
  timeOutline,
} from 'ionicons/icons';
import { ParametreService } from '../../core/services/parametre.service';
import { MessageSms, ParametresSms, RechargementSms } from '../../core/models/parametre.model';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE } from '../../shared/pagination.component';

/**
 * Parametres > SMS aux parents (V1 : info_etablissement.is_used_sms /
 * sms_sender / number_sms, tables sms et rechargement_sms). Le fournisseur
 * est commun a la plateforme ; l'ecole regle son expediteur et suit son credit.
 */
@Component({
  selector: 'app-parametres-sms',
  standalone: true,
  imports: [IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, SelecteurComponent, PaginationComponent],
  templateUrl: './parametres-sms.page.html',
  styleUrls: ['./parametres.commun.scss', './parametres-sms.page.scss'],
})
export class ParametresSmsPage implements OnInit {
  private readonly service = inject(ParametreService);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly sms = signal<ParametresSms | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal(false);

  // Reglages
  readonly actif = signal(false);
  readonly expediteur = signal('');
  readonly erreurExpediteur = signal<string | null>(null);
  readonly enregistrement = signal(false);
  readonly modifie = computed(() => {
    const s = this.sms();
    return !!s && (this.actif() !== s.sms_actif || this.expediteur().trim() !== (s.sms_expediteur ?? ''));
  });

  // SMS de test
  readonly numeroTest = signal('');
  readonly messageTest = signal('Test Monde Éducatif : les SMS de votre établissement fonctionnent.');
  readonly envoiTest = signal(false);
  readonly segmentsTest = computed(() => Math.max(1, Math.ceil(this.messageTest().length / 160)));

  // Rechargements (pagination serveur)
  readonly rechargements = signal<RechargementSms[]>([]);
  readonly totalRecharges = signal(0);
  readonly pageRecharges = signal(1);
  readonly tailleRecharges = signal(TAILLES_PAGE[0]);
  readonly rechargeOuverte = signal(false);
  readonly recharge = signal({ nombre_sms: '', montant: '', commentaire: '' });
  readonly erreursRecharge = signal<Record<string, string>>({});
  readonly enregistrementRecharge = signal(false);

  // Historique (pagination serveur)
  readonly messages = signal<MessageSms[]>([]);
  readonly totalMessages = signal(0);
  readonly pageMessages = signal(1);
  readonly tailleMessages = signal(TAILLES_PAGE[0]);
  readonly recherche = signal('');
  readonly filtreStatut = signal(0);
  readonly chargementMessages = signal(false);
  private minuterieRecherche?: ReturnType<typeof setTimeout>;

  readonly optionsStatut: OptionSelecteur[] = [
    { valeur: 0, libelle: 'Tous les envois' },
    { valeur: 1, libelle: 'Envoyés', badge: { texte: 'Envoyé', ton: 'succes' } },
    { valeur: 2, libelle: 'En échec', badge: { texte: 'Échec', ton: 'neutre' } },
  ];

  constructor() {
    addIcons({
      chevronBackOutline,
      chatbubblesOutline,
      walletOutline,
      sendOutline,
      settingsOutline,
      addOutline,
      closeOutline,
      searchOutline,
      warningOutline,
      informationCircleOutline,
      cloudOfflineOutline,
      checkmarkCircle,
      closeCircle,
      timeOutline,
    });
  }

  ngOnInit(): void {
    this.charger();
  }

  charger(): void {
    this.chargement.set(true);
    this.erreur.set(false);
    this.service.sms().subscribe({
      next: (s) => {
        this.appliquer(s);
        this.chargement.set(false);
        this.chargerRecharges();
        this.chargerMessages();
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
  }

  // ------------------------------------------------------------ Reglages

  enregistrer(): void {
    const expediteur = this.expediteur().trim();
    if (this.actif() && !expediteur) {
      this.erreurExpediteur.set("Indiquez le nom de l'expéditeur pour activer les SMS.");
      return;
    }
    this.enregistrement.set(true);
    this.service.enregistrerSms({ sms_actif: this.actif(), sms_expediteur: expediteur || null }).subscribe({
      next: (s) => {
        this.enregistrement.set(false);
        this.appliquer(s);
        this.notifier('Réglages SMS enregistrés.');
      },
      error: (e: HttpErrorResponse) => {
        this.enregistrement.set(false);
        this.erreurExpediteur.set(e.error?.errors?.sms_expediteur?.[0] ?? null);
        this.notifier(this.premiereErreur(e), true);
      },
    });
  }

  annuler(): void {
    const s = this.sms();
    if (s) {
      this.appliquer(s);
    }
  }

  // ------------------------------------------------------------ Test

  envoyerTest(): void {
    this.envoiTest.set(true);
    this.service.testerSms(this.numeroTest(), this.messageTest()).subscribe({
      next: (r) => {
        this.envoiTest.set(false);
        this.sms.set(r.data);
        this.notifier(r.message);
        this.chargerMessages();
      },
      error: (e: HttpErrorResponse) => {
        this.envoiTest.set(false);
        this.notifier(e.error?.message && !e.error?.errors ? e.error.message : this.premiereErreur(e), true);
        this.chargerMessages();
      },
    });
  }

  // ------------------------------------------------------------ Rechargements

  chargerRecharges(): void {
    this.service.rechargementsSms(this.pageRecharges(), this.tailleRecharges()).subscribe({
      next: (p) => {
        this.rechargements.set(p.data);
        this.totalRecharges.set(p.total);
      },
    });
  }

  pageRechargesChange(page: number): void {
    this.pageRecharges.set(page);
    this.chargerRecharges();
  }

  tailleRechargesChange(taille: number): void {
    this.tailleRecharges.set(taille);
    this.pageRecharges.set(1);
    this.chargerRecharges();
  }

  ouvrirRecharge(): void {
    this.recharge.set({ nombre_sms: '', montant: '', commentaire: '' });
    this.erreursRecharge.set({});
    this.rechargeOuverte.set(true);
  }

  majRecharge(modif: Partial<{ nombre_sms: string; montant: string; commentaire: string }>): void {
    this.recharge.update((r) => ({ ...r, ...modif }));
    this.erreursRecharge.set({});
  }

  enregistrerRecharge(): void {
    const r = this.recharge();
    const nombre = Number(r.nombre_sms);
    const montant = Number(r.montant || 0);
    const erreurs: Record<string, string> = {};
    if (!Number.isInteger(nombre) || nombre < 1) {
      erreurs['nombre_sms'] = 'Nombre de SMS achetés (entier, au moins 1).';
    }
    if (!Number.isInteger(montant) || montant < 0) {
      erreurs['montant'] = 'Montant entier positif.';
    }
    if (Object.keys(erreurs).length) {
      this.erreursRecharge.set(erreurs);
      return;
    }
    this.enregistrementRecharge.set(true);
    this.service.rechargerSms({ nombre_sms: nombre, montant, commentaire: r.commentaire.trim() || null }).subscribe({
      next: (s) => {
        this.enregistrementRecharge.set(false);
        this.rechargeOuverte.set(false);
        this.sms.set(s);
        this.pageRecharges.set(1);
        this.chargerRecharges();
        this.notifier(`${nombre} SMS ajoutés au crédit.`);
      },
      error: (e: HttpErrorResponse) => {
        this.enregistrementRecharge.set(false);
        this.notifier(this.premiereErreur(e), true);
      },
    });
  }

  // ------------------------------------------------------------ Historique

  chargerMessages(): void {
    this.chargementMessages.set(true);
    const statut = this.filtreStatut() === 1 ? 'envoye' : this.filtreStatut() === 2 ? 'echec' : null;
    this.service.messagesSms(this.pageMessages(), this.tailleMessages(), { recherche: this.recherche(), statut }).subscribe({
      next: (p) => {
        this.messages.set(p.data);
        this.totalMessages.set(p.total);
        this.chargementMessages.set(false);
      },
      error: () => this.chargementMessages.set(false),
    });
  }

  rechercher(valeur: string): void {
    this.recherche.set(valeur);
    clearTimeout(this.minuterieRecherche);
    this.minuterieRecherche = setTimeout(() => {
      this.pageMessages.set(1);
      this.chargerMessages();
    }, 350);
  }

  filtrer(statut: number): void {
    this.filtreStatut.set(statut);
    this.pageMessages.set(1);
    this.chargerMessages();
  }

  pageMessagesChange(page: number): void {
    this.pageMessages.set(page);
    this.chargerMessages();
  }

  tailleMessagesChange(taille: number): void {
    this.tailleMessages.set(taille);
    this.pageMessages.set(1);
    this.chargerMessages();
  }

  // ------------------------------------------------------------ Affichage

  montant(valeur: number | null | undefined): string {
    return new Intl.NumberFormat('fr-FR').format(valeur ?? 0);
  }

  date(valeur: string): string {
    return new Date(valeur).toLocaleString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  private appliquer(s: ParametresSms): void {
    this.sms.set(s);
    this.actif.set(s.sms_actif);
    this.expediteur.set(s.sms_expediteur ?? '');
    this.erreurExpediteur.set(null);
  }

  private premiereErreur(e: HttpErrorResponse): string {
    return (
      (e.error?.errors && (Object.values(e.error.errors)[0] as string[])[0]) ||
      e.error?.message ||
      'Action impossible. Vérifiez votre connexion puis réessayez.'
    );
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3500, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
