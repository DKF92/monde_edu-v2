import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastController, IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  cashOutline,
  calendarOutline,
  schoolOutline,
  listOutline,
  printOutline,
  searchOutline,
  cloudOfflineOutline,
  chevronForwardOutline,
  informationCircleOutline,
  optionsOutline,
  closeOutline,
  documentsOutline,
  maleFemaleOutline,
  repeatOutline,
  layersOutline,
  peopleOutline,
  eyeOutline,
} from 'ionicons/icons';
import { CaisseService } from '../../core/services/caisse.service';
import { ActionsCaisseService } from '../../core/services/actions-caisse.service';
import { BilanCaisse, CriteresBilan, FiltresBilan, LigneBilan } from '../../core/models/caisse.model';
import { OptionSelecteur, SelecteurComponent } from '../../shared/selecteur.component';

type Colonne = 'affecte' | 'non_affecte' | 'total';

/**
 * Point de caisse : bilan des paiements encaisses par l'utilisateur connecte,
 * par type de frais et statut de l'eleve, sur une journee / des dates / un
 * mois / l'annee scolaire ; chaque montant ouvre la liste des paiements
 * concernes ; bilan imprimable (PDF genere par l'API).
 */
@Component({
  selector: 'app-point-caisse',
  standalone: true,
  imports: [IonContent, IonIcon, IonModal, IonSkeletonText, IonSpinner, SelecteurComponent],
  templateUrl: './point-caisse.page.html',
  styleUrl: './point-caisse.page.scss',
})
export class PointCaissePage {
  private readonly caisse = inject(CaisseService);
  private readonly toasts = inject(ToastController);
  readonly router = inject(Router);
  readonly actions = inject(ActionsCaisseService);

  readonly colonnes: Colonne[] = ['affecte', 'non_affecte', 'total'];
  readonly libellesColonnes: Record<Colonne, string> = { affecte: 'Affectés', non_affecte: 'Non affectés', total: 'Total' };

  readonly bilan = signal<BilanCaisse | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal<string | null>(null);
  readonly impression = signal<'bilan' | 'general' | null>(null);
  readonly modaleFiltres = signal(false);

  /** Filtre applique (celui du bilan affiche). */
  readonly filtres = signal<FiltresBilan>({ periode: 'jour' });
  /** Saisie des dates (appliquee par "Afficher"). */
  readonly du = signal(this.aujourdhui());
  readonly au = signal(this.aujourdhui());
  readonly erreurDates = signal<string | null>(null);

  readonly moisDisponibles = computed(() => this.bilan()?.mois_disponibles ?? []);
  /** Selecteur (valeurs numeriques) : 0 = aucun mois, sinon rang + 1. */
  readonly optionsMois = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Choisir un mois…' },
    ...this.moisDisponibles().map((m, i) => ({ valeur: i + 1, libelle: m.libelle })),
  ]);
  readonly rangMois = computed(() => {
    const f = this.filtres();
    return f.periode === 'mois' ? this.moisDisponibles().findIndex((m) => m.valeur === f.mois) + 1 : 0;
  });

  // ------------------------------------------------ Criteres eleve (valeurs numeriques des selecteurs)
  readonly optionsSexe: OptionSelecteur[] = [
    { valeur: 0, libelle: 'Tous' },
    { valeur: 1, libelle: 'Filles' },
    { valeur: 2, libelle: 'Garçons' },
  ];
  readonly optionsRedoublant: OptionSelecteur[] = [
    { valeur: 0, libelle: 'Tous' },
    { valeur: 1, libelle: 'Oui' },
    { valeur: 2, libelle: 'Non' },
  ];
  readonly cycles = computed(() => this.bilan()?.criteres_disponibles.cycles ?? []);
  readonly optionsCycle = computed<OptionSelecteur[]>(() => [{ valeur: 0, libelle: 'Tous' }, ...this.cycles().map((c, i) => ({ valeur: i + 1, libelle: c.libelle }))]);
  readonly optionsNiveau = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Tous' },
    ...(this.bilan()?.criteres_disponibles.niveaux ?? []).map((n) => ({ valeur: n.id, libelle: n.libelle })),
  ]);
  readonly optionsClasse = computed<OptionSelecteur[]>(() => [
    { valeur: 0, libelle: 'Toutes' },
    ...(this.bilan()?.criteres_disponibles.classes ?? []).map((c) => ({ valeur: c.id, libelle: c.libelle })),
  ]);

  readonly valeurSexe = computed(() => (this.filtres().sexe === 'F' ? 1 : this.filtres().sexe === 'M' ? 2 : 0));
  readonly valeurRedoublant = computed(() => (this.filtres().redoublant === true ? 1 : this.filtres().redoublant === false ? 2 : 0));
  readonly valeurCycle = computed(() => this.cycles().findIndex((c) => c.valeur === this.filtres().cycle) + 1);
  /** Nombre de criteres eleve actifs (badge du bouton Filtres). */
  readonly nombreCriteres = computed(() => {
    const f = this.filtres();
    return [f.sexe, f.redoublant ?? null, f.cycle, f.niveau_id, f.classe_id].filter((v) => v !== null && v !== undefined && v !== '').length;
  });

  /** Groupes de lignes (intertitres du tableau). */
  groupe(l: LigneBilan): string {
    return l.nature === 'annexe' ? 'Frais annexes' : l.nature === 'dette' ? 'Dettes' : 'Frais d\'inscription et scolarité';
  }

  constructor() {
    addIcons({
      cashOutline,
      calendarOutline,
      schoolOutline,
      listOutline,
      printOutline,
      searchOutline,
      cloudOfflineOutline,
      chevronForwardOutline,
      informationCircleOutline,
      optionsOutline,
      closeOutline,
      documentsOutline,
      maleFemaleOutline,
      repeatOutline,
      layersOutline,
      peopleOutline,
      eyeOutline,
    });
  }

  /** Retour sur la page (apres un encaissement) : bilan a jour sans F5. */
  ionViewWillEnter(): void {
    this.charger();
  }

  charger(filtres = this.filtres()): void {
    this.chargement.set(true);
    this.erreur.set(null);
    this.caisse.bilan(filtres).subscribe({
      next: (b) => {
        this.filtres.set(filtres);
        this.bilan.set(b);
        this.chargement.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.chargement.set(false);
        const erreurs = e.error?.errors as Record<string, string[]> | undefined;
        const message = (erreurs && Object.values(erreurs)[0]?.[0]) || e.error?.message || 'Impossible de charger le bilan.';
        if (erreurs) {
          this.erreurDates.set(message);
        } else {
          this.erreur.set(message);
        }
      },
    });
  }

  // ------------------------------------------------ Periodes

  afficherDates(): void {
    this.erreurDates.set(null);
    if (!this.du()) {
      this.erreurDates.set('Saisissez la date de début.');
      return;
    }
    if (this.au() && this.au() < this.du()) {
      this.erreurDates.set('La date de fin doit suivre la date de début.');
      return;
    }
    this.charger({ ...this.criteres(), periode: 'dates', du: this.du(), au: this.au() || this.du() });
  }

  choisirMois(rang: number): void {
    const mois = this.moisDisponibles()[rang - 1];
    if (mois) {
      this.erreurDates.set(null);
      this.charger({ ...this.criteres(), periode: 'mois', mois: mois.valeur });
    }
  }

  anneeScolaire(): void {
    this.erreurDates.set(null);
    this.charger({ ...this.criteres(), periode: 'annee' });
  }

  aujourdhuiBilan(): void {
    this.erreurDates.set(null);
    this.du.set(this.aujourdhui());
    this.au.set(this.aujourdhui());
    this.charger({ ...this.criteres(), periode: 'jour' });
  }

  // ------------------------------------------------ Criteres

  choisirSexe(v: number): void {
    this.majCriteres({ sexe: v === 1 ? 'F' : v === 2 ? 'M' : null });
  }

  choisirRedoublant(v: number): void {
    this.majCriteres({ redoublant: v === 1 ? true : v === 2 ? false : null });
  }

  /** Cycle, niveau et classe s'excluent : les deux autres sont grises. */
  choisirCycle(rang: number): void {
    this.majCriteres({ cycle: this.cycles()[rang - 1]?.valeur ?? null, niveau_id: null, classe_id: null });
  }

  choisirNiveau(id: number): void {
    this.majCriteres({ niveau_id: id || null, cycle: null, classe_id: null });
  }

  choisirClasse(id: number): void {
    this.majCriteres({ classe_id: id || null, cycle: null, niveau_id: null });
  }

  effacerCriteres(): void {
    this.majCriteres({ sexe: null, redoublant: null, cycle: null, niveau_id: null, classe_id: null });
  }

  private majCriteres(changement: CriteresBilan): void {
    this.charger({ ...this.filtres(), ...changement });
  }

  private criteres(): CriteresBilan {
    const { sexe, redoublant, cycle, niveau_id, classe_id } = this.filtres();
    return { sexe, redoublant, cycle, niveau_id, classe_id };
  }

  // ------------------------------------------------ Actions

  /** Liste des paiements de la periode (tous frais) ou d'une cellule du tableau. */
  liste(ligne?: LigneBilan, colonne: Colonne = 'total'): void {
    const b = this.bilan();
    if (!b) {
      return;
    }
    const params: Record<string, string | number> = {
      periode: b.periode.periode === 'annee' ? 'annee' : 'dates',
      moi: 1,
      titre: b.titre,
    };
    if (b.periode.du) params['du'] = b.periode.du;
    if (b.periode.au) params['au'] = b.periode.au;
    const c = this.criteres();
    if (c.sexe) params['sexe'] = c.sexe;
    if (c.redoublant !== null && c.redoublant !== undefined) params['redoublant'] = c.redoublant ? 1 : 0;
    if (c.cycle) params['cycle'] = c.cycle;
    if (c.niveau_id) params['niveau_id'] = c.niveau_id;
    if (c.classe_id) params['classe_id'] = c.classe_id;
    const morceaux: string[] = [];
    if (ligne?.dette) {
      params['dette'] = 1;
    } else if (ligne?.type_frais_id) {
      params['type_frais_id'] = ligne.type_frais_id;
    }
    if (ligne) morceaux.push(ligne.libelle);
    if (colonne !== 'total') {
      params['affecte'] = colonne === 'affecte' ? 1 : 0;
      morceaux.push(colonne === 'affecte' ? 'élèves affectés' : 'élèves non affectés');
    }
    if (morceaux.length) params['cellule'] = morceaux.join(' · ');
    this.router.navigate(['/tabs/paiements/liste'], { queryParams: params });
  }

  async imprimer(general = false): Promise<void> {
    this.impression.set(general ? 'general' : 'bilan');
    const titre = this.bilan()?.titre;
    const ok = await (general ? this.caisse.ouvrirBilanGeneral(this.filtres(), 'Bilan général · ' + titre) : this.caisse.ouvrirBilan(this.filtres(), titre));
    this.impression.set(null);
    if (!ok) {
      const toast = await this.toasts.create({ message: 'Impossible d\'ouvrir le bilan. Autorisez les fenêtres (pop-up) puis réessayez.', duration: 3500, color: 'danger' });
      await toast.present();
    }
  }

  // ------------------------------------------------ Affichage

  f(v: number): string {
    return v.toLocaleString('fr-FR') + ' F';
  }

  applicable(l: LigneBilan, colonne: Colonne): boolean {
    return colonne === 'total' || (colonne === 'affecte' ? l.applicable_affecte : l.applicable_non_affecte) || l[colonne] > 0;
  }

  valeurTexte(evenement: Event): string {
    return (evenement.target as HTMLInputElement).value;
  }

  private aujourdhui(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }
}
