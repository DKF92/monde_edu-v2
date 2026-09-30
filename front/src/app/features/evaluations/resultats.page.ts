import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AlertController, ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  printOutline,
  documentTextOutline,
  lockClosedOutline,
  saveOutline,
  calendarOutline,
  schoolOutline,
  cloudOfflineOutline,
  informationCircleOutline,
  swapVerticalOutline,
} from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { LIBELLES_DECISION, LigneResultat, OptionsEvaluations, PedagogieService, Resultats } from '../../core/services/pedagogie.service';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE, paginer } from '../../shared/pagination.component';

/** Periode "Annuelle" dans le selecteur (valeurs numeriques). */
const ANNUELLE = -1;

/**
 * Resultats d'une classe (V1 moy_classe, matrice_moy, bulletin) : moyennes
 * par matiere, moyenne generale, rang, distinctions ; periode ou annuelle ;
 * impression des resultats et des bulletins ; arret des moyennes,
 * enregistrement des resultats et decisions de fin d'annee (moyennes.gerer).
 */
@Component({
  selector: 'app-resultats',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner, SelecteurComponent, PaginationComponent],
  templateUrl: './resultats.page.html',
  styleUrl: './evaluations.scss',
})
export class ResultatsPage {
  private readonly service = inject(PedagogieService);
  private readonly auth = inject(AuthService);
  private readonly alertes = inject(AlertController);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly libellesDecision = LIBELLES_DECISION;
  readonly peutSaisir = computed(() => this.auth.aPermission('notes.saisir') || this.auth.aPermission('moyennes.gerer'));

  readonly options = signal<OptionsEvaluations | null>(null);
  readonly erreurOptions = signal(false);
  readonly classeId = signal(0);
  readonly periode = signal(0);
  readonly resultats = signal<Resultats | null>(null);
  readonly chargement = signal(false);
  readonly erreur = signal(false);
  readonly action = signal<string | null>(null);
  readonly ordreMerite = signal(true);
  /** Decisions modifiees (inscription_id -> decision). */
  readonly decisions = signal<Record<number, string>>({});

  readonly optionsClasse = computed<OptionSelecteur[]>(() => (this.options()?.classes ?? []).map((c) => ({ valeur: c.id, libelle: c.libelle })));
  readonly optionsPeriode = computed<OptionSelecteur[]>(() => [
    ...(this.options()?.periodes ?? []).map((p) => ({ valeur: p.id, libelle: p.libelle })),
    { valeur: ANNUELLE, libelle: 'Annuelle' },
  ]);

  readonly eleves = computed<LigneResultat[]>(() => {
    const l = [...(this.resultats()?.eleves ?? [])];
    return this.ordreMerite() ? l.sort((a, b) => (a.rang ?? 9999) - (b.rang ?? 9999) || a.nom.localeCompare(b.nom)) : l.sort((a, b) => a.nom.localeCompare(b.nom) || a.prenoms.localeCompare(b.prenoms));
  });
  readonly decisionsModifiees = computed(() => Object.keys(this.decisions()).length > 0);
  readonly page = signal(1);
  readonly taille = signal(TAILLES_PAGE[1]);
  readonly elevesPage = computed(() => paginer(this.eleves(), this.page(), this.taille()));
  readonly provisoires = computed(() => (this.resultats()?.matieres ?? []).filter((m) => !m.arretee && m.evaluations > 0));
  readonly libellesProvisoires = computed(() => this.provisoires().map((m) => m.libelle).join(', '));

  constructor() {
    addIcons({ printOutline, documentTextOutline, lockClosedOutline, saveOutline, calendarOutline, schoolOutline, cloudOfflineOutline, informationCircleOutline, swapVerticalOutline });
  }

  ionViewWillEnter(): void {
    this.service.options().subscribe({
      next: (o) => {
        this.options.set(o);
        this.erreurOptions.set(false);
        if (!o.classes.some((c) => c.id === this.classeId())) this.classeId.set(o.classes[0]?.id ?? 0);
        if (this.periode() !== ANNUELLE && !o.periodes.some((p) => p.id === this.periode())) {
          this.periode.set((o.periodes.find((p) => p.active) ?? o.periodes[0])?.id ?? 0);
        }
        this.charger();
      },
      error: () => this.erreurOptions.set(true),
    });
  }

  choisirClasse(id: number): void {
    this.classeId.set(id);
    this.charger();
  }

  choisirPeriode(id: number): void {
    this.periode.set(id);
    this.charger();
  }

  charger(): void {
    if (!this.classeId() || !this.periode()) return;
    this.chargement.set(true);
    this.erreur.set(false);
    this.decisions.set({});
    this.service.resultats(this.classeId(), this.valeurPeriode()).subscribe({
      next: (r) => {
        this.resultats.set(r);
        this.page.set(1);
        this.chargement.set(false);
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
  }

  // ------------------------------------------------ Impressions

  async imprimerResultats(): Promise<void> {
    const r = this.resultats();
    if (!r) return;
    this.action.set('resultats');
    await this.service.imprimerResultats(r.classe, this.valeurPeriode(), r.periode.libelle);
    this.action.set(null);
  }

  async imprimerBulletins(eleve?: LigneResultat): Promise<void> {
    const r = this.resultats();
    if (!r) return;
    this.action.set(eleve ? 'bulletin-' + eleve.eleve_id : 'bulletins');
    await this.service.imprimerBulletins(r.classe, this.valeurPeriode(), r.periode.libelle, eleve ? { id: eleve.eleve_id, nom: `${eleve.nom} ${eleve.prenoms}` } : undefined);
    this.action.set(null);
  }

  // ------------------------------------------------ Gestion (moyennes.gerer)

  enregistrerResultats(): void {
    const r = this.resultats();
    if (!r?.periode.id) return;
    this.action.set('enregistrer');
    this.service.enregistrerResultats(r.classe.id, r.periode.id).subscribe({
      next: (x) => this.terminer(x.message),
      error: (e: HttpErrorResponse) => this.echec(e),
    });
  }

  decisionDe(l: LigneResultat): string {
    return this.decisions()[l.inscription_id] ?? l.decision ?? '';
  }

  choisirDecision(l: LigneResultat, decision: string): void {
    this.decisions.update((d) => ({ ...d, [l.inscription_id]: decision }));
  }

  /** Reprend les propositions (admis a partir de 10) pour les eleves sans decision. */
  proposer(): void {
    const d: Record<number, string> = { ...this.decisions() };
    for (const l of this.resultats()?.eleves ?? []) {
      if (!this.decisionDe(l) && l.decision_proposee) d[l.inscription_id] = l.decision_proposee;
    }
    this.decisions.set(d);
  }

  enregistrerDecisions(): void {
    const r = this.resultats();
    if (!r) return;
    this.action.set('decisions');
    this.service
      .enregistrerDecisions(r.classe.id, Object.entries(this.decisions()).map(([id, decision]) => ({ inscription_id: Number(id), decision: decision || null })))
      .subscribe({
        next: (x) => this.terminer(x.message),
        error: (e: HttpErrorResponse) => this.echec(e),
      });
  }

  // ------------------------------------------------ Affichage

  n(v: number | null | undefined): string {
    return v === null || v === undefined ? '—' : v.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  rang(r: number | null, ex: boolean): string {
    return r ? `${r}${r === 1 ? 'er' : 'e'}${ex ? ' ex' : ''}` : '—';
  }

  valeurSelect(evenement: Event): string {
    return (evenement.target as HTMLSelectElement).value;
  }

  private valeurPeriode(): number | 'annuel' {
    return this.periode() === ANNUELLE ? 'annuel' : this.periode();
  }

  private terminer(message: string): void {
    this.action.set(null);
    this.notifier(message);
    this.charger();
  }

  private echec(e: HttpErrorResponse): void {
    this.action.set(null);
    const erreurs = e.error?.errors as Record<string, string[]> | undefined;
    this.notifier((erreurs && Object.values(erreurs)[0]?.[0]) || e.error?.message || 'Action impossible.', true);
  }

  private async confirmer(titre: string, message: string, bouton: string): Promise<boolean> {
    const alerte = await this.alertes.create({ header: titre, message, cssClass: 'alerte-me', buttons: [{ text: 'Annuler', role: 'cancel' }, { text: bouton, role: 'confirm' }] });
    await alerte.present();
    return (await alerte.onDidDismiss()).role === 'confirm';
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
