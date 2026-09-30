import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { RouterLink } from '@angular/router';
import {
  IonHeader,
  IonToolbar,
  IonContent,
  IonRefresher,
  IonRefresherContent,
  IonIcon,
  IonSkeletonText,
} from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  peopleOutline,
  schoolOutline,
  cashOutline,
  walletOutline,
  trendingUpOutline,
  calendarOutline,
  chevronDownOutline,
  searchOutline,
  flagOutline,
  cloudOfflineOutline,
  refreshOutline,
  calendarClearOutline,
  libraryOutline,
} from 'ionicons/icons';
import { DashboardService } from '../../core/services/dashboard.service';
import { AuthService } from '../../core/services/auth.service';
import { NavigationService } from '../../core/services/navigation.service';
import { EspaceTravailService } from '../../core/services/espace-travail.service';
import { ICONES_NAVIGATION } from '../../core/navigation';
import { StatsDashboard } from '../../core/models/eleve.model';
import { LogoComponent } from '../../shared/logo.component';

interface Evenement {
  date: Date;
  titre: string;
  detail: string;
  teinte: 'bleu' | 'vert' | 'orange';
}

interface Indicateur {
  libelle: string;
  valeur: string;
  icone: string;
  teinte: 'bleu' | 'vert' | 'violet' | 'orange' | 'cyan';
}

const NB_EVENEMENTS = 4;

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [
    RouterLink,
    IonHeader,
    IonToolbar,
    IonContent,
    IonRefresher,
    IonRefresherContent,
    IonIcon,
    IonSkeletonText,
    LogoComponent,
  ],
  templateUrl: './dashboard.page.html',
  styleUrl: './dashboard.page.scss',
})
export class DashboardPage {
  private readonly dashboardService = inject(DashboardService);
  private readonly auth = inject(AuthService);
  private readonly navigation = inject(NavigationService);
  readonly espaceTravail = inject(EspaceTravailService);

  readonly stats = signal<StatsDashboard | null>(null);
  readonly chargement = signal(true);
  readonly erreur = signal(false);

  readonly prenom = this.auth.prenom;
  readonly etablissement = this.auth.etablissementActif;
  readonly anneeScolaire = this.auth.anneeScolaire;
  readonly periode = this.auth.periode;
  readonly accesRapides = this.navigation.accesRapides;
  readonly peutVoirEleves = computed(() => {
    this.auth.user();
    return this.auth.aPermission('eleves.voir');
  });

  readonly dateDuJour = this.capitaliser(
    new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date()),
  );

  readonly salutation = new Date().getHours() >= 18 ? 'Bonsoir' : 'Bonjour';

  readonly indicateurs = computed<Indicateur[]>(() => {
    const s = this.stats();
    if (!s) {
      return [];
    }

    const liste: Indicateur[] = [
      { libelle: 'Élèves actifs', valeur: this.formaterNombre(s.effectif_eleves), icone: 'people-outline', teinte: 'bleu' },
      { libelle: 'Classes ouvertes', valeur: this.formaterNombre(s.nombre_classes), icone: 'school-outline', teinte: 'cyan' },
    ];

    if (s.finances) {
      liste.push(
        { libelle: "Encaissé aujourd'hui", valeur: this.formaterMontant(s.finances.encaissements_du_jour), icone: 'cash-outline', teinte: 'vert' },
        { libelle: 'Encaissé ce mois', valeur: this.formaterMontant(s.finances.encaissements_du_mois), icone: 'trending-up-outline', teinte: 'violet' },
        { libelle: 'Reste à recouvrer', valeur: this.formaterMontant(s.finances.reste_a_recouvrer), icone: 'wallet-outline', teinte: 'orange' },
      );
    }

    return liste;
  });

  /** Avancement du trimestre (null si ses dates ne sont pas renseignees). */
  readonly avancementPeriode = computed(() => {
    const periode = this.stats()?.periode;
    if (!periode?.date_debut || !periode.date_fin) {
      return null;
    }

    const debut = this.dateLocale(periode.date_debut).getTime();
    const fin = this.dateLocale(periode.date_fin).getTime();
    const maintenant = this.aujourdhui().getTime();
    const jour = 24 * 3600 * 1000;

    return {
      pourcentage: Math.round(Math.min(1, Math.max(0, (maintenant - debut) / (fin - debut || 1))) * 100),
      joursRestants: Math.max(0, Math.round((fin - maintenant) / jour)),
      debut: this.formaterDateCourte(periode.date_debut),
      fin: this.formaterDateCourte(periode.date_fin),
    };
  });

  /** Fin de trimestre + dates limites de versement, par ordre chronologique. */
  readonly evenements = computed<Evenement[]>(() => {
    const s = this.stats();
    if (!s) {
      return [];
    }

    const liste: Evenement[] = s.echeances.map((echeance) => ({
      date: this.dateLocale(echeance.date),
      titre: `${echeance.libelle} des frais`,
      detail: `${echeance.detail} · ${this.formaterMontant(echeance.montant)}`,
      teinte: 'vert',
    }));

    if (s.periode?.date_fin && this.dateLocale(s.periode.date_fin) >= this.aujourdhui()) {
      liste.push({
        date: this.dateLocale(s.periode.date_fin),
        titre: `Fin du ${s.periode.libelle}`,
        detail: 'Clôture de la saisie des notes',
        teinte: 'orange',
      });
    }

    return liste.sort((a, b) => a.date.getTime() - b.date.getTime()).slice(0, NB_EVENEMENTS);
  });

  readonly classesRestantes = computed(() => {
    const s = this.stats();
    return s ? Math.max(0, s.classes_total - s.classes.length) : 0;
  });

  constructor() {
    addIcons({
      ...ICONES_NAVIGATION,
      peopleOutline,
      schoolOutline,
      cashOutline,
      walletOutline,
      trendingUpOutline,
      calendarOutline,
      chevronDownOutline,
      searchOutline,
      flagOutline,
      cloudOfflineOutline,
      refreshOutline,
      calendarClearOutline,
      libraryOutline,
    });

    // Recharge automatiquement a chaque changement d'espace de travail
    // (les en-tetes envoyes a l'API changent).
    effect(
      () => {
        this.auth.etablissementActif();
        this.auth.anneeScolaire();
        this.auth.periode();
        this.auth.poste();
        untracked(() => this.charger());
      },
      { allowSignalWrites: true },
    );
  }

  charger(evenement?: CustomEvent): void {
    if (!this.auth.contexteComplet()) {
      // La modale bloquante de la coquille demande d'abord l'espace de travail.
      (evenement?.target as HTMLIonRefresherElement | undefined)?.complete();
      return;
    }

    this.chargement.set(!this.stats());
    this.erreur.set(false);

    this.dashboardService.stats().subscribe({
      next: (stats) => {
        this.stats.set(stats);
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

  jour(date: Date): string {
    return String(date.getDate());
  }

  mois(date: Date): string {
    return new Intl.DateTimeFormat('fr-FR', { month: 'short' }).format(date);
  }

  private formaterNombre(valeur: number): string {
    return new Intl.NumberFormat('fr-FR').format(valeur);
  }

  private formaterMontant(montant: number): string {
    return `${this.formaterNombre(montant)} F`;
  }

  private formaterDateCourte(iso: string): string {
    return new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short' }).format(this.dateLocale(iso));
  }

  /** "2026-09-27" -> minuit heure locale (new Date(iso) serait en UTC). */
  private dateLocale(iso: string): Date {
    const [annee, mois, jour] = iso.split('-').map(Number);
    return new Date(annee, mois - 1, jour);
  }

  private aujourdhui(): Date {
    const d = new Date();
    return new Date(d.getFullYear(), d.getMonth(), d.getDate());
  }

  private capitaliser(texte: string): string {
    return texte.charAt(0).toUpperCase() + texte.slice(1);
  }
}
