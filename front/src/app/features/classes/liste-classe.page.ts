import { Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { ActivatedRoute, Router } from '@angular/router';
import { ToastController, IonContent, IonIcon, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  chevronBackOutline,
  searchOutline,
  printOutline,
  schoolOutline,
  peopleOutline,
  maleOutline,
  femaleOutline,
  checkmarkCircleOutline,
  alertCircleOutline,
  personOutline,
  businessOutline,
  languageOutline,
  cloudOfflineOutline,
  chevronForwardOutline,
  bookOutline,
  repeatOutline,
  arrowForwardCircleOutline,
} from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { ClasseService, EleveClasse, LIBELLES_CYCLE, ListeDeClasse } from '../../core/services/classe.service';
import { EnseignantsClasse, PedagogieService } from '../../core/services/pedagogie.service';
import { LIBELLES_STATUT, TONS_STATUT } from '../../core/models/inscription.model';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';
import { PaginationComponent, TAILLES_PAGE, paginer } from '../../shared/pagination.component';

type Filtre = 'tous' | 'M' | 'F' | 'affectes' | 'non_affectes' | 'redoublants' | 'non_redoublants';

/**
 * Liste de classe (V1 liste_eleve_classe) : eleves de la classe par ordre
 * alphabetique, compteurs (sexe, affectation), recherche, passage d'une
 * classe a l'autre, impression (tous, affectes, non affectes, par sexe).
 */
@Component({
  selector: 'app-liste-classe',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, IonSpinner, IonRefresher, IonRefresherContent, SelecteurComponent, PaginationComponent],
  templateUrl: './liste-classe.page.html',
  styleUrl: './liste-classe.page.scss',
})
export class ListeClassePage {
  private readonly service = inject(ClasseService);
  private readonly pedagogie = inject(PedagogieService);
  private readonly toasts = inject(ToastController);
  private readonly auth = inject(AuthService);
  private readonly route = inject(ActivatedRoute);
  readonly router = inject(Router);

  readonly libellesCycle = LIBELLES_CYCLE;
  readonly libellesStatut = LIBELLES_STATUT;
  readonly tons = TONS_STATUT;
  /** Clic sur un eleve : fiche d'inscription, sinon dossier de l'eleve. */
  readonly peutOuvrir = computed(() => this.auth.aPermission('inscriptions.gerer') || this.auth.aPermission('eleves.voir'));

  /** Professeurs de la classe (V1 enseigner) : classes.gerer ou matieres.gerer pour les changer. */
  readonly peutAffecter = computed(() => this.auth.aPermission('classes.gerer') || this.auth.aPermission('matieres.gerer'));
  readonly enseignants = signal<EnseignantsClasse | null>(null);
  readonly choixEnseignants = signal<Record<number, number>>({});
  readonly enregistrementEnseignants = signal(false);
  readonly enseignantsModifies = computed(() => (this.enseignants()?.matieres ?? []).some((m) => (this.choixEnseignants()[m.id] ?? 0) !== (m.personnel_id ?? 0)));

  readonly donnees = signal<ListeDeClasse | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal(false);
  readonly impression = signal(false);

  readonly filtre = signal<Filtre>('tous');
  readonly recherche = signal('');
  readonly page = signal(1);
  readonly taille = signal(TAILLES_PAGE[0]);

  readonly optionsClasses = computed<OptionSelecteur[]>(() => (this.donnees()?.autres ?? []).map((c) => ({ valeur: c.id, libelle: c.libelle })));

  readonly cartes = computed(() => {
    const c = this.donnees()?.classe;
    return [
      { filtre: 'tous' as Filtre, nombre: c?.effectif ?? 0, libelle: 'Élèves', icone: 'people-outline', teinte: 'cyan' },
      { filtre: 'M' as Filtre, nombre: c?.garcons ?? 0, libelle: 'Garçons', icone: 'male-outline', teinte: 'violet' },
      { filtre: 'F' as Filtre, nombre: c?.filles ?? 0, libelle: 'Filles', icone: 'female-outline', teinte: 'rose' },
      { filtre: 'affectes' as Filtre, nombre: c?.affectes ?? 0, libelle: 'Affectés', icone: 'checkmark-circle-outline', teinte: 'vert' },
      { filtre: 'non_affectes' as Filtre, nombre: c?.non_affectes ?? 0, libelle: 'Non affectés', icone: 'alert-circle-outline', teinte: 'orange' },
      { filtre: 'redoublants' as Filtre, nombre: c?.redoublants ?? 0, libelle: 'Doublants', icone: 'repeat-outline', teinte: 'bleu' },
      { filtre: 'non_redoublants' as Filtre, nombre: (c?.effectif ?? 0) - (c?.redoublants ?? 0), libelle: 'Non doublants', icone: 'arrow-forward-circle-outline', teinte: 'vert' },
    ];
  });

  readonly eleves = computed(() => {
    const f = this.filtre();
    const texte = this.normaliser(this.recherche());
    return (this.donnees()?.eleves ?? []).filter(
      (e) =>
        (f === 'tous' || (f === 'M' && e.sexe === 'M') || (f === 'F' && e.sexe === 'F') || (f === 'affectes' && e.affecte) || (f === 'non_affectes' && !e.affecte) ||
          (f === 'redoublants' && e.redoublant) || (f === 'non_redoublants' && !e.redoublant)) &&
        (!texte || this.normaliser(`${e.nom} ${e.prenoms} ${e.matricule}`).includes(texte)),
    );
  });
  /** Numero d'ordre dans la liste complete (alphabetique), comme sur le document. */
  readonly rangs = computed(() => new Map((this.donnees()?.eleves ?? []).map((e, i) => [e.inscription_id, i + 1])));
  readonly elevesPage = computed(() => paginer(this.eleves(), this.page(), this.taille()));

  readonly libelleFiltre = computed(
    () => ({ tous: '', M: 'Garçons', F: 'Filles', affectes: 'Élèves affectés', non_affectes: 'Élèves non affectés', redoublants: 'Doublants', non_redoublants: 'Non doublants' })[this.filtre()],
  );

  readonly titre = computed(() => {
    const n = this.eleves().length;
    const m = [this.libelleFiltre() ? `${n} · ${this.libelleFiltre().toLowerCase()}` : `${n} élève${n > 1 ? 's' : ''}`];
    if (this.recherche().trim()) m.push(`pour « ${this.recherche().trim()} »`);
    return m.join(' ');
  });

  constructor() {
    addIcons({
      chevronBackOutline,
      searchOutline,
      printOutline,
      schoolOutline,
      peopleOutline,
      maleOutline,
      femaleOutline,
      checkmarkCircleOutline,
      alertCircleOutline,
      personOutline,
      businessOutline,
      languageOutline,
      cloudOfflineOutline,
      chevronForwardOutline,
      bookOutline,
      repeatOutline,
      arrowForwardCircleOutline,
    });
  }

  /** Page gardee en memoire par Ionic : on recharge a chaque affichage. */
  ionViewWillEnter(): void {
    this.charger();
  }

  charger(evenement?: CustomEvent): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (this.donnees()?.classe.id !== id) {
      this.donnees.set(null);
      this.chargement.set(true);
      this.filtre.set('tous');
      this.recherche.set('');
      this.page.set(1);
    }
    this.erreur.set(false);
    this.pedagogie.enseignants(id).subscribe({ next: (e) => this.appliquerEnseignants(e), error: () => this.enseignants.set(null) });
    this.service.liste(id).subscribe({
      next: (d) => {
        this.donnees.set(d);
        this.chargement.set(false);
        (evenement?.target as HTMLIonRefresherElement | undefined)?.complete();
      },
      error: () => {
        this.chargement.set(false);
        this.erreur.set(true);
        (evenement?.target as HTMLIonRefresherElement | undefined)?.complete();
      },
    });
  }

  /** Passage a une autre classe (V1 : liste deroulante des classes). */
  changerClasse(id: number): void {
    if (id !== this.donnees()?.classe.id) {
      this.router.navigate(['/tabs/classes', id], { replaceUrl: true });
    }
  }

  basculer(filtre: Filtre): void {
    this.filtre.set(this.filtre() === filtre ? 'tous' : filtre);
    this.page.set(1);
  }

  rechercher(texte: string): void {
    this.recherche.set(texte);
    this.page.set(1);
  }

  changerTaille(taille: number): void {
    this.taille.set(taille);
    this.page.set(1);
  }

  ouvrir(e: EleveClasse): void {
    if (this.auth.aPermission('inscriptions.gerer')) {
      this.router.navigate(['/tabs/inscriptions', e.inscription_id]);
    } else if (this.auth.aPermission('eleves.voir')) {
      this.router.navigate(['/tabs/eleves', e.eleve_id]);
    }
  }

  async imprimer(): Promise<void> {
    const d = this.donnees();
    if (!d) return;
    const f = this.filtre();
    this.impression.set(true);
    await this.service.imprimerListe(
      d.classe,
      {
        affecte: f === 'affectes' ? true : f === 'non_affectes' ? false : null,
        sexe: f === 'M' || f === 'F' ? f : null,
        redoublant: f === 'redoublants' ? true : f === 'non_redoublants' ? false : null,
      },
      this.libelleFiltre(),
    );
    this.impression.set(false);
  }

  /** Professeurs proposes pour une matiere : ceux qui l'enseignent d'abord. */
  optionsProfesseurs(matiereId: number): OptionSelecteur[] {
    const p = [...(this.enseignants()?.personnels ?? [])].sort((a, b) => Number(b.matieres.includes(matiereId)) - Number(a.matieres.includes(matiereId)) || a.nom.localeCompare(b.nom));
    return [{ valeur: 0, libelle: 'Non désigné' }, ...p.map((x) => ({ valeur: x.id, libelle: x.nom + (x.matieres.includes(matiereId) ? '' : ' (autre matière)') }))];
  }

  choisirProfesseur(matiereId: number, personnelId: number): void {
    this.choixEnseignants.update((c) => ({ ...c, [matiereId]: personnelId }));
  }

  enregistrerEnseignants(): void {
    const d = this.donnees();
    const e = this.enseignants();
    if (!d || !e) return;
    this.enregistrementEnseignants.set(true);
    this.pedagogie.enregistrerEnseignants(d.classe.id, e.matieres.map((m) => ({ matiere_id: m.id, personnel_id: this.choixEnseignants()[m.id] || null }))).subscribe({
      next: (r) => {
        this.enregistrementEnseignants.set(false);
        this.appliquerEnseignants(r);
        this.notifier('Professeurs de la classe enregistrés.');
      },
      error: (err: HttpErrorResponse) => {
        this.enregistrementEnseignants.set(false);
        this.notifier(err.error?.message || 'Enregistrement impossible.', true);
      },
    });
  }

  annulerEnseignants(): void {
    const e = this.enseignants();
    if (e) this.appliquerEnseignants(e);
  }

  nomProfesseur(personnelId: number | null): string {
    return this.enseignants()?.personnels.find((p) => p.id === personnelId)?.nom ?? 'Non désigné';
  }

  private appliquerEnseignants(e: EnseignantsClasse): void {
    this.enseignants.set(e);
    this.choixEnseignants.set(Object.fromEntries(e.matieres.map((m) => [m.id, m.personnel_id ?? 0])));
  }

  private async notifier(message: string, erreur = false): Promise<void> {
    const toast = await this.toasts.create({ message, duration: 3000, position: 'bottom', color: erreur ? 'danger' : 'dark' });
    await toast.present();
  }

  dateFr(date: string | null): string {
    return date ? date.slice(0, 10).split('-').reverse().join('/') : '—';
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  private normaliser(texte: string): string {
    return texte.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
  }
}
