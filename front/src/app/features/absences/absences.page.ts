import { Component, OnDestroy, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable } from 'rxjs';
import { AlertController, ToastController, IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  addOutline,
  searchOutline,
  printOutline,
  ribbonOutline,
  timeOutline,
  checkmarkCircleOutline,
  alertCircleOutline,
  peopleOutline,
  calendarOutline,
  schoolOutline,
  createOutline,
  trashOutline,
  closeOutline,
  cloudOfflineOutline,
  checkboxOutline,
  squareOutline,
  documentTextOutline,
} from 'ionicons/icons';
import { Absence, EleveAppel, FiltresAbsences, PageAbsences, PedagogieService } from '../../core/services/pedagogie.service';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE } from '../../shared/pagination.component';
import { ListeProgressive } from '../../shared/liste-progressive';

type Etat = 'toutes' | 'non' | 'oui';

/**
 * Absences des eleves (V1 EDUCATEUR enregistrer_abs / consult_abs /
 * just_abs, PROFESSEUR appel) : appel de la classe, liste filtree, compteurs
 * d'heures, justification, modification, suppression, impression.
 */
@Component({
  selector: 'app-absences',
  standalone: true,
  imports: [IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent, SelecteurComponent, PaginationComponent],
  templateUrl: './absences.page.html',
  styleUrl: './absences.page.scss',
})
export class AbsencesPage implements OnDestroy {
  private readonly service = inject(PedagogieService);
  private readonly alertes = inject(AlertController);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly liste = new ListeProgressive<Absence>(50);
  readonly infos = signal<Omit<PageAbsences, 'data' | 'total' | 'page' | 'par_page'> | null>(null);

  readonly periodeId = signal(0);
  readonly classeId = signal(0);
  readonly etat = signal<Etat>('toutes');
  readonly recherche = signal('');
  readonly page = signal(1);
  readonly taille = signal(TAILLES_PAGE[0]);
  readonly impression = signal(false);

  readonly lignesPage = computed(() => this.liste.page(this.page(), this.taille()));
  readonly pageEnAttente = computed(() => this.liste.pageEnAttente(this.page(), this.taille()));

  readonly optionsPeriode = computed<OptionSelecteur[]>(() => [{ valeur: 0, libelle: 'Toute l\'année' }, ...(this.infos()?.periodes ?? []).map((p) => ({ valeur: p.id, libelle: p.libelle }))]);
  readonly optionsClasse = computed<OptionSelecteur[]>(() => [{ valeur: 0, libelle: 'Toutes les classes' }, ...(this.infos()?.classes ?? []).map((c) => ({ valeur: c.id, libelle: c.libelle }))]);
  readonly optionsPeriodeSaisie = computed<OptionSelecteur[]>(() => (this.infos()?.periodes ?? []).map((p) => ({ valeur: p.id, libelle: p.libelle + (p.cloturee ? ' (clôturée)' : '') })));
  readonly optionsClasseSaisie = computed<OptionSelecteur[]>(() => (this.infos()?.classes ?? []).map((c) => ({ valeur: c.id, libelle: c.libelle })));

  readonly titre = computed(() => {
    const n = this.liste.total();
    const m = [`${n} absence${n > 1 ? 's' : ''}`];
    const p = this.infos()?.periodes.find((x) => x.id === this.periodeId());
    if (p) m.push(p.libelle);
    const c = this.infos()?.classes.find((x) => x.id === this.classeId());
    if (c) m.push(c.libelle);
    if (this.etat() !== 'toutes') m.push(this.etat() === 'oui' ? 'justifiées' : 'non justifiées');
    if (this.recherche().trim()) m.push(`pour « ${this.recherche().trim()} »`);
    return m.join(' · ');
  });

  // Appel / modification (fenetre).
  readonly modale = signal(false);
  readonly edition = signal<Absence | null>(null);
  readonly sPeriode = signal(0);
  readonly sClasse = signal(0);
  readonly sDebut = signal('');
  readonly sFin = signal('');
  readonly sHeures = signal('');
  readonly sJustifiee = signal(false);
  readonly sMotif = signal('');
  readonly elevesAppel = signal<EleveAppel[]>([]);
  readonly chargementAppel = signal(false);
  readonly absents = signal<Set<number>>(new Set());
  readonly tentative = signal(false);
  readonly erreursServeur = signal<string[]>([]);
  readonly enregistrement = signal(false);

  readonly controles = computed(() => {
    const e: Record<string, string> = {};
    if (!this.sPeriode()) e['periode'] = 'Choisissez la période.';
    if (!this.edition() && !this.sClasse()) e['classe'] = 'Choisissez la classe.';
    if (!this.sDebut()) e['debut'] = 'Indiquez la date.';
    else if (this.sDebut() > this.aujourdhui()) e['debut'] = 'La date ne peut pas être dans le futur.';
    if (this.sFin() && this.sFin() < this.sDebut()) e['fin'] = 'La date de fin suit la date de début.';
    const h = Number(this.sHeures().replace(',', '.'));
    if (!this.sHeures().trim() || !Number.isFinite(h) || h < 0.5 || h > 200) e['heures'] = 'Nombre d\'heures entre 0,5 et 200.';
    if (!this.edition() && !this.absents().size) e['eleves'] = 'Cochez au moins un élève absent.';
    return e;
  });

  private minuterie?: ReturnType<typeof setTimeout>;

  constructor() {
    addIcons({
      addOutline, searchOutline, printOutline, ribbonOutline, timeOutline, checkmarkCircleOutline, alertCircleOutline, peopleOutline,
      calendarOutline, schoolOutline, createOutline, trashOutline, closeOutline, cloudOfflineOutline, checkboxOutline, squareOutline, documentTextOutline,
    });
  }

  ionViewWillEnter(): void {
    this.charger();
  }

  ionViewWillLeave(): void {
    this.liste.arreter();
  }

  ngOnDestroy(): void {
    this.liste.arreter();
    clearTimeout(this.minuterie);
  }

  charger(evenement?: CustomEvent): void {
    this.liste.charger(
      (page, parPage) => this.service.absences({ ...this.filtres(), page, par_page: parPage }),
      (r) => {
        const { data: _d, total: _t, page: _p, par_page: _pp, ...infos } = r;
        this.infos.set(infos);
        (evenement?.target as HTMLIonRefresherElement | undefined)?.complete();
      },
    );
  }

  choisirPeriode(id: number): void {
    this.periodeId.set(id);
    this.relancer();
  }

  choisirClasse(id: number): void {
    this.classeId.set(id);
    this.relancer();
  }

  choisirEtat(e: Etat): void {
    this.etat.set(e);
    this.relancer();
  }

  rechercher(texte: string): void {
    this.recherche.set(texte);
    this.page.set(1);
    this.liste.arreter();
    clearTimeout(this.minuterie);
    this.minuterie = setTimeout(() => this.charger(), 250);
  }

  changerTaille(taille: number): void {
    this.taille.set(taille);
    this.page.set(1);
  }

  async imprimer(): Promise<void> {
    this.impression.set(true);
    await this.service.imprimerAbsences(this.filtres(), 'Absences · ' + this.titre());
    this.impression.set(false);
  }

  // ------------------------------------------------ Appel et modification

  appel(): void {
    const active = this.infos()?.periodes.find((p) => p.active) ?? this.infos()?.periodes[0];
    this.edition.set(null);
    this.sPeriode.set(this.periodeId() || active?.id || 0);
    this.sClasse.set(this.classeId() || 0);
    this.sDebut.set(this.aujourdhui());
    this.sFin.set('');
    this.sHeures.set('');
    this.sJustifiee.set(false);
    this.sMotif.set('');
    this.absents.set(new Set());
    this.tentative.set(false);
    this.erreursServeur.set([]);
    this.elevesAppel.set([]);
    this.modale.set(true);
    if (this.sClasse()) this.chargerAppel();
  }

  modifier(a: Absence, evenement?: Event): void {
    evenement?.stopPropagation();
    this.edition.set(a);
    this.sPeriode.set(a.periode_id ?? 0);
    this.sClasse.set(a.classe_id ?? 0);
    this.sDebut.set(a.date_debut);
    this.sFin.set(a.date_fin ?? '');
    this.sHeures.set(String(a.nombre_heures).replace('.', ','));
    this.sJustifiee.set(a.is_justifiee);
    this.sMotif.set(a.motif ?? '');
    this.tentative.set(false);
    this.erreursServeur.set([]);
    this.modale.set(true);
  }

  choisirClasseAppel(id: number): void {
    this.sClasse.set(id);
    this.absents.set(new Set());
    this.chargerAppel();
  }

  basculerAbsent(id: number): void {
    this.absents.update((s) => {
      const n = new Set(s);
      n.has(id) ? n.delete(id) : n.add(id);
      return n;
    });
  }

  erreur(champ: string): string | null {
    return this.tentative() ? this.controles()[champ] ?? null : null;
  }

  enregistrer(): void {
    this.tentative.set(true);
    if (Object.keys(this.controles()).length) return;
    const saisie = {
      periode_id: this.sPeriode(),
      date_debut: this.sDebut(),
      date_fin: this.sFin() || null,
      nombre_heures: Number(this.sHeures().replace(',', '.')),
      is_justifiee: this.sJustifiee(),
      motif: this.sMotif().trim() || null,
    };
    const edition = this.edition();
    this.enregistrement.set(true);
    this.erreursServeur.set([]);
    const requete: Observable<unknown> = edition ? this.service.modifierAbsence(edition.id, saisie) : this.service.creerAbsences({ ...saisie, eleve_ids: [...this.absents()] });
    requete.subscribe({
      next: (r) => {
        this.enregistrement.set(false);
        this.modale.set(false);
        this.notifier(edition ? 'Absence modifiée.' : (r as { message: string }).message);
        this.charger();
      },
      error: (e: HttpErrorResponse) => {
        this.enregistrement.set(false);
        const erreurs = e.error?.errors as Record<string, string[]> | undefined;
        this.erreursServeur.set(erreurs ? Object.values(erreurs).map((m) => m[0]) : [e.error?.message || 'Enregistrement impossible.']);
      },
    });
  }

  justifier(a: Absence, evenement?: Event): void {
    evenement?.stopPropagation();
    this.service.justifier(a.id, !a.is_justifiee, a.motif).subscribe({
      next: (maj) => {
        this.liste.lignes.update((l) => l.map((x) => (x.id === maj.id ? maj : x)));
        this.notifier(maj.is_justifiee ? 'Absence justifiée.' : 'Absence marquée non justifiée.');
        this.charger();
      },
      error: (e: HttpErrorResponse) => this.notifier(e.error?.message || 'Action impossible.', true),
    });
  }

  async supprimer(a: Absence, evenement?: Event): Promise<void> {
    evenement?.stopPropagation();
    const alerte = await this.alertes.create({
      header: 'Supprimer l\'absence',
      message: `L'absence de ${a.eleve?.nom} ${a.eleve?.prenoms} du ${this.dateFr(a.date_debut)} (${this.h(a.nombre_heures)} h) sera supprimée.`,
      cssClass: 'alerte-me',
      buttons: [{ text: 'Annuler', role: 'cancel' }, { text: 'Supprimer', role: 'confirm' }],
    });
    await alerte.present();
    if ((await alerte.onDidDismiss()).role !== 'confirm') return;
    this.service.supprimerAbsence(a.id).subscribe({
      next: (r) => {
        this.notifier(r.message);
        this.charger();
      },
      error: (e: HttpErrorResponse) => this.notifier(e.error?.message || 'Suppression impossible.', true),
    });
  }

  // ------------------------------------------------ Affichage

  h(v: number): string {
    return v.toLocaleString('fr-FR', { maximumFractionDigits: 1 });
  }

  dateFr(date: string | null): string {
    return date ? date.slice(0, 10).split('-').reverse().join('/') : '';
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  private chargerAppel(): void {
    if (!this.sClasse()) return;
    this.chargementAppel.set(true);
    this.service.elevesAppel(this.sClasse(), this.sPeriode() || null).subscribe({
      next: (r) => {
        this.elevesAppel.set(r.eleves);
        this.chargementAppel.set(false);
      },
      error: () => this.chargementAppel.set(false),
    });
  }

  private filtres(): FiltresAbsences {
    return {
      periode_id: this.periodeId() || null,
      classe_id: this.classeId() || null,
      justifiee: this.etat() === 'toutes' ? null : this.etat() === 'oui',
      recherche: this.recherche(),
    };
  }

  private relancer(): void {
    this.page.set(1);
    this.charger();
  }

  private aujourdhui(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
