import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AlertController, ToastController, IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  addOutline,
  createOutline,
  trashOutline,
  closeOutline,
  lockClosedOutline,
  lockOpenOutline,
  calendarOutline,
  schoolOutline,
  bookOutline,
  cloudOfflineOutline,
  informationCircleOutline,
  barChartOutline,
} from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { GrilleNotes, OptionsEvaluations, PedagogieService } from '../../core/services/pedagogie.service';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE, paginer } from '../../shared/pagination.component';

/**
 * Saisie des notes (V1 Note/new_note) : periode, classe, matiere ; tableau
 * des evaluations (notee sur 10, 20 ou 40) et moyennes ; saisie d'une
 * evaluation dans une fenetre ; arret / reouverture des moyennes.
 */
@Component({
  selector: 'app-notes',
  standalone: true,
  imports: [IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, SelecteurComponent, PaginationComponent],
  templateUrl: './notes.page.html',
  styleUrl: './evaluations.scss',
})
export class NotesPage {
  private readonly service = inject(PedagogieService);
  private readonly auth = inject(AuthService);
  private readonly alertes = inject(AlertController);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);

  readonly peutVoirResultats = computed(() => ['notes.voir', 'moyennes.gerer', 'bulletins.voir'].some((p) => this.auth.aPermission(p)));

  readonly options = signal<OptionsEvaluations | null>(null);
  readonly erreurOptions = signal(false);
  readonly periodeId = signal(0);
  readonly classeId = signal(0);
  readonly matiereId = signal(0);

  readonly grille = signal<GrilleNotes | null>(null);
  readonly page = signal(1);
  readonly taille = signal(TAILLES_PAGE[1]);
  readonly elevesPage = computed(() => paginer(this.grille()?.eleves ?? [], this.page(), this.taille()));
  readonly chargement = signal(false);
  readonly erreur = signal(false);

  // Saisie d'une evaluation (fenetre).
  readonly modale = signal(false);
  readonly numero = signal<number | null>(null);
  readonly bareme = signal(20);
  readonly date = signal('');
  readonly valeurs = signal<Partial<Record<number, string>>>({});
  readonly erreursServeur = signal<string[]>([]);
  readonly enregistrement = signal(false);

  readonly optionsPeriode = computed<OptionSelecteur[]>(() => (this.options()?.periodes ?? []).map((p) => ({ valeur: p.id, libelle: p.libelle + (p.cloturee ? ' (clôturée)' : '') })));
  readonly optionsClasse = computed<OptionSelecteur[]>(() => (this.options()?.classes ?? []).map((c) => ({ valeur: c.id, libelle: c.libelle })));
  readonly classe = computed(() => this.options()?.classes.find((c) => c.id === this.classeId()) ?? null);
  readonly optionsMatiere = computed<OptionSelecteur[]>(() => (this.classe()?.matieres ?? []).map((m) => ({ valeur: m.id, libelle: `${m.libelle} (coef ${m.coefficient})` })));

  readonly saisies = computed(() => Object.values(this.valeurs()).filter((v) => (v ?? '').trim() !== '').length);
  readonly invalides = computed(() => {
    const b = this.bareme();
    return new Set(Object.entries(this.valeurs()).filter(([, v]) => (v ?? '').trim() !== '' && !this.valide(v ?? '', b)).map(([id]) => Number(id)));
  });

  constructor() {
    addIcons({
      addOutline,
      createOutline,
      trashOutline,
      closeOutline,
      lockClosedOutline,
      lockOpenOutline,
      calendarOutline,
      schoolOutline,
      bookOutline,
      cloudOfflineOutline,
      informationCircleOutline,
      barChartOutline,
    });
  }

  ionViewWillEnter(): void {
    this.service.options().subscribe({
      next: (o) => {
        this.options.set(o);
        this.erreurOptions.set(false);
        if (!o.periodes.some((p) => p.id === this.periodeId())) {
          this.periodeId.set((o.periodes.find((p) => p.active) ?? o.periodes[0])?.id ?? 0);
        }
        if (!o.classes.some((c) => c.id === this.classeId())) {
          this.classeId.set(o.classes[0]?.id ?? 0);
        }
        this.ajusterMatiere();
        this.charger();
      },
      error: () => this.erreurOptions.set(true),
    });
  }

  choisirPeriode(id: number): void {
    this.periodeId.set(id);
    this.charger();
  }

  choisirClasse(id: number): void {
    this.classeId.set(id);
    this.ajusterMatiere();
    this.charger();
  }

  choisirMatiere(id: number): void {
    this.matiereId.set(id);
    this.charger();
  }

  charger(): void {
    if (!this.periodeId() || !this.classeId() || !this.matiereId()) {
      this.grille.set(null);
      return;
    }
    this.chargement.set(true);
    this.erreur.set(false);
    this.service.notes(this.classeId(), this.matiereId(), this.periodeId()).subscribe({
      next: (g) => {
        this.grille.set(g);
        this.page.set(1);
        this.chargement.set(false);
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
      },
    });
  }

  // ------------------------------------------------ Saisie

  nouvelle(): void {
    this.ouvrir(null, 20, this.aujourdhui(), {});
  }

  modifier(numero: number): void {
    const g = this.grille();
    const e = g?.evaluations.find((x) => x.numero === numero);
    if (!g || !e) return;
    this.ouvrir(numero, e.bareme, e.date ?? this.aujourdhui(), Object.fromEntries(g.eleves.map((l) => [l.eleve_id, l.notes[numero]?.toString() ?? ''])));
  }

  saisir(eleveId: number, valeur: string): void {
    this.valeurs.update((v) => ({ ...v, [eleveId]: valeur.replace(',', '.') }));
  }

  /** Entree : passe a l'eleve suivant (saisie rapide au clavier). */
  suivant(evenement: Event, index: number): void {
    evenement.preventDefault();
    (document.getElementById(`note-${index + 1}`) as HTMLInputElement | null)?.focus();
  }

  enregistrer(): void {
    const g = this.grille();
    if (!g || this.invalides().size) return;
    if (!this.saisies()) {
      this.erreursServeur.set(['Saisissez au moins une note.']);
      return;
    }
    this.enregistrement.set(true);
    this.erreursServeur.set([]);
    this.service
      .enregistrerEvaluation({
        classe_id: g.classe.id,
        matiere_id: g.matiere.id,
        periode_id: g.periode.id,
        numero: this.numero(),
        bareme: this.bareme(),
        date: this.date() || null,
        notes: g.eleves.map((l) => ({ eleve_id: l.eleve_id, valeur: this.valeurs()[l.eleve_id]?.trim() ? Number(this.valeurs()[l.eleve_id]) : null })),
      })
      .subscribe({
        next: (r) => {
          this.enregistrement.set(false);
          this.modale.set(false);
          this.grille.set(r);
          this.notifier(`Évaluation n°${r.numero} enregistrée.`);
        },
        error: (e: HttpErrorResponse) => {
          this.enregistrement.set(false);
          const erreurs = e.error?.errors as Record<string, string[]> | undefined;
          this.erreursServeur.set(erreurs ? Object.values(erreurs).map((m) => m[0]) : [e.error?.message || 'Enregistrement impossible.']);
        },
      });
  }

  async supprimer(numero: number): Promise<void> {
    const g = this.grille();
    if (!g) return;
    if (!(await this.confirmer('Supprimer l\'évaluation', `Les notes de l'évaluation n°${numero} (${g.matiere.libelle}) seront supprimées.`, 'Supprimer'))) return;
    this.service.supprimerEvaluation(g.classe.id, g.matiere.id, g.periode.id, numero).subscribe({
      next: (r) => {
        this.grille.set(r);
        this.notifier(`Évaluation n°${numero} supprimée.`);
      },
      error: (e: HttpErrorResponse) => this.notifier(e.error?.message || 'Suppression impossible.', true),
    });
  }

  // ------------------------------------------------ Affichage

  n(v: number | null | undefined): string {
    return v === null || v === undefined ? '—' : v.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  note(v: number | undefined): string {
    return v === undefined ? '' : v.toLocaleString('fr-FR', { maximumFractionDigits: 2 });
  }

  rang(r: number | null, ex: boolean): string {
    return r ? `${r}${r === 1 ? 'er' : 'e'}${ex ? ' ex' : ''}` : '—';
  }

  dateFr(date: string | null): string {
    return date ? date.slice(0, 10).split('-').reverse().join('/') : '';
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  private ouvrir(numero: number | null, bareme: number, date: string, valeurs: Partial<Record<number, string>>): void {
    this.numero.set(numero);
    this.bareme.set(bareme);
    this.date.set(date);
    this.valeurs.set(valeurs);
    this.erreursServeur.set([]);
    this.modale.set(true);
    setTimeout(() => (document.getElementById('note-0') as HTMLInputElement | null)?.focus(), 400);
  }

  private valide(v: string, bareme: number): boolean {
    const n = Number(v);
    return Number.isFinite(n) && n >= 0 && n <= bareme;
  }

  private ajusterMatiere(): void {
    const matieres = this.classe()?.matieres ?? [];
    if (!matieres.some((m) => m.id === this.matiereId())) {
      this.matiereId.set(matieres[0]?.id ?? 0);
    }
  }

  private aujourdhui(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  private async confirmer(titre: string, message: string, bouton: string): Promise<boolean> {
    const alerte = await this.alertes.create({
      header: titre,
      message,
      cssClass: 'alerte-me',
      buttons: [{ text: 'Annuler', role: 'cancel' }, { text: bouton, role: 'confirm' }],
    });
    await alerte.present();
    return (await alerte.onDidDismiss()).role === 'confirm';
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }
}
