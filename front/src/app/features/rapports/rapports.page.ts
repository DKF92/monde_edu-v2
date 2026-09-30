import { Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  peopleOutline,
  femaleOutline,
  schoolOutline,
  timeOutline,
  createOutline,
  checkmarkCircleOutline,
  printOutline,
  calendarOutline,
  chevronForwardOutline,
  closeOutline,
  cloudOfflineOutline,
  barChartOutline,
  trophyOutline,
  podiumOutline,
  listOutline,
  gitBranchOutline,
  alarmOutline,
  hourglassOutline,
  personAddOutline,
  libraryOutline,
  ribbonOutline,
  warningOutline,
  walletOutline,
  cashOutline,
  calendarNumberOutline,
  pricetagOutline,
  eyeOffOutline,
  eyeOutline,
  arrowUndoOutline,
} from 'ionicons/icons';
import { AccueilRapports, ColonneRapport, PedagogieService, Rapport } from '../../core/services/pedagogie.service';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { RapportsOfficielsComponent } from './rapports-officiels.component';
import { RapportsPersonnalisesComponent } from './rapports-personnalises.component';

/** "Annuelle" dans le selecteur de periode (valeurs numeriques). */
const ANNUELLE = -1;

/**
 * Rapports (V1 DirecteurEtude/rapports.php) : chiffres cles, puis chaque
 * rapport affiche en tableau et imprimable (PDF, Word, Excel).
 */
@Component({
  selector: 'app-rapports',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner, SelecteurComponent, RapportsOfficielsComponent, RapportsPersonnalisesComponent],
  templateUrl: './rapports.page.html',
  styleUrl: './rapports.page.scss',
})
export class RapportsPage {
  private readonly service = inject(PedagogieService);
  private readonly toasts = inject(ToastController);

  readonly icones: Partial<Record<string, string>> = {
    effectifs: 'people-outline',
    resultats: 'bar-chart-outline',
    majors: 'trophy-outline',
    merite: 'podium-outline',
    decisions: 'git-branch-outline',
    absences: 'alarm-outline',
    ages: 'hourglass-outline',
    nouveaux: 'person-add-outline',
    matieres: 'library-outline',
    distinctions: 'ribbon-outline',
    difficulte: 'warning-outline',
    absents: 'time-outline',
    finances_classes: 'wallet-outline',
    recettes_frais: 'cash-outline',
    recettes_mois: 'calendar-number-outline',
    reductions: 'pricetag-outline',
  };
  /** Teinte de la pastille par rubrique. */
  readonly teintes: Partial<Record<string, string>> = { Effectifs: 'teinte-bleu', 'Pédagogie': 'teinte-violet', 'Vie scolaire': 'teinte-orange', Finances: 'teinte-vert' };

  /** Affiche aussi les rapports retires (pour les remettre). */
  readonly voirRetires = signal(false);
  readonly masquage = signal<string | null>(null);
  readonly rubriques = computed(() => {
    const a = this.accueil();
    if (!a) return [];
    return a.rubriques
      .map((titre) => ({ titre, rapports: a.rapports.filter((r) => r.rubrique === titre && (!r.masque || this.voirRetires())) }))
      .filter((g) => g.rapports.length);
  });
  readonly retires = computed(() => this.accueil()?.rapports.filter((r) => r.masque).length ?? 0);

  readonly accueil = signal<AccueilRapports | null>(null);
  readonly erreurAccueil = signal(false);
  readonly code = signal<string | null>(null);
  readonly periode = signal(0);
  readonly affecte = signal(0);
  readonly alphabetique = signal(false);
  readonly rapport = signal<Rapport | null>(null);
  readonly chargement = signal(false);
  readonly impression = signal(false);

  readonly choisi = computed(() => this.accueil()?.rapports.find((r) => r.code === this.code()) ?? null);
  readonly optionsPeriode = computed<OptionSelecteur[]>(() => {
    const periodes = (this.accueil()?.periodes ?? []).map((p) => ({ valeur: p.id, libelle: p.libelle }));
    return this.choisi()?.periode ? [...periodes, { valeur: ANNUELLE, libelle: 'Annuelle' }] : [{ valeur: 0, libelle: 'Toute l\'année' }, ...periodes];
  });
  readonly optionsAffecte: OptionSelecteur[] = [
    { valeur: 0, libelle: 'Tous les élèves' },
    { valeur: 1, libelle: 'Affectés' },
    { valeur: 2, libelle: 'Non affectés' },
  ];

  constructor() {
    addIcons({
      peopleOutline, femaleOutline, schoolOutline, timeOutline, createOutline, checkmarkCircleOutline, printOutline, calendarOutline,
      chevronForwardOutline, closeOutline, cloudOfflineOutline, barChartOutline, trophyOutline, podiumOutline, listOutline, gitBranchOutline, alarmOutline,
      hourglassOutline, personAddOutline, libraryOutline, ribbonOutline, warningOutline, walletOutline, cashOutline, calendarNumberOutline, pricetagOutline,
      eyeOffOutline, eyeOutline, arrowUndoOutline,
    });
  }

  ionViewWillEnter(): void {
    this.service.accueilRapports().subscribe({
      next: (a) => {
        this.accueil.set(a);
        this.erreurAccueil.set(false);
        if (this.code()) this.charger();
      },
      error: () => this.erreurAccueil.set(true),
    });
  }

  ouvrir(code: string): void {
    this.code.set(code);
    const a = this.accueil();
    const r = a?.rapports.find((x) => x.code === code);
    // Periode : la periode active pour les rapports de resultats, toute l'annee sinon.
    this.periode.set(r?.periode ? ((a?.periodes.find((p) => p.active) ?? a?.periodes[0])?.id ?? ANNUELLE) : 0);
    this.rapport.set(null);
    this.charger();
    setTimeout(() => document.getElementById('rapport-choisi')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);
  }

  /** Retire (ou remet) un rapport du catalogue pour tout l'etablissement. */
  basculerMasque(code: string, evenement: Event): void {
    evenement.stopPropagation();
    const a = this.accueil();
    if (!a) return;
    const masques = a.rapports.filter((r) => r.masque).map((r) => r.code);
    const retirer = !masques.includes(code);
    const codes = retirer ? [...masques, code] : masques.filter((c) => c !== code);
    this.masquage.set(code);
    this.service.masquerRapports(codes).subscribe({
      next: (r) => {
        this.masquage.set(null);
        this.accueil.set({ ...a, rapports: a.rapports.map((x) => ({ ...x, masque: r.masques.includes(x.code) })) });
        if (retirer && this.code() === code) this.fermer();
        const titre = a.rapports.find((x) => x.code === code)?.titre;
        this.notifier(retirer ? `« ${titre} » est retiré des rapports.` : `« ${titre} » est remis dans les rapports.`);
      },
      error: (e: HttpErrorResponse) => {
        this.masquage.set(null);
        this.notifier(e.error?.message || 'Action impossible.', true);
      },
    });
  }

  fermer(): void {
    this.code.set(null);
    this.rapport.set(null);
  }

  choisirPeriode(v: number): void {
    this.periode.set(v);
    this.charger();
  }

  choisirAffecte(v: number): void {
    this.affecte.set(v);
    this.charger();
  }

  basculerOrdre(): void {
    this.alphabetique.set(!this.alphabetique());
    this.charger();
  }

  charger(): void {
    const code = this.code();
    if (!code) return;
    this.chargement.set(true);
    this.service.rapport(code, this.parametres()).subscribe({
      next: (r) => {
        this.rapport.set(r);
        this.chargement.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.chargement.set(false);
        this.notifier(e.error?.message || 'Impossible de charger le rapport.', true);
      },
    });
  }

  async imprimer(): Promise<void> {
    const r = this.rapport();
    if (!r) return;
    this.impression.set(true);
    await this.service.imprimerRapport(r.code, this.parametres(), r.titre + (r.periode ? ' · ' + r.periode : ''));
    this.impression.set(false);
  }

  // ------------------------------------------------ Affichage

  valeur(ligne: Record<string, unknown> | null, c: ColonneRapport): string {
    const v = ligne?.[c.cle];
    if (v === null || v === undefined || v === '') return c.type === 'moyenne' || c.type === 'pourcent' ? '—' : '';
    if (c.type === 'moyenne') return (v as number).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (c.type === 'pourcent') return (v as number).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' %';
    if (c.type === 'nombre') return (v as number).toLocaleString('fr-FR', { maximumFractionDigits: 1 });
    if (c.type === 'montant') return (v as number).toLocaleString('fr-FR') + ' F';
    return String(v);
  }

  alignement(c: ColonneRapport): string {
    return c.type === 'nombre' || c.type === 'moyenne' || c.type === 'pourcent' || c.type === 'montant' ? 'd' : c.type === 'centre' ? 'c' : 'g';
  }

  h(v: number): string {
    return v.toLocaleString('fr-FR', { maximumFractionDigits: 1 });
  }

  private parametres(): Record<string, string | number> {
    const p: Record<string, string | number> = {};
    if (this.periode()) p['periode'] = this.periode() === ANNUELLE ? 'annuel' : this.periode();
    if (this.code() === 'merite') {
      if (this.affecte()) p['affecte'] = this.affecte() === 1 ? 1 : 0;
      if (this.alphabetique()) p['ordre'] = 'alpha';
    }
    return p;
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
