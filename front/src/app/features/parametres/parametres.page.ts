import { Component, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IonContent, IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import {
  schoolOutline,
  personAddOutline,
  cashOutline,
  businessOutline,
  calendarOutline,
  chatbubblesOutline,
  chevronForwardOutline,
  bookOutline,
  lockClosedOutline,
  swapVerticalOutline,
} from 'ionicons/icons';
import { AuthService } from '../../core/services/auth.service';
import { Teinte } from '../../core/navigation';

interface RubriqueParametres {
  titre: string;
  description: string;
  icone: string;
  teinte: Teinte;
  route: string | null;
  permission: string;
}

/** Accueil des parametres : une carte par type de parametres. */
@Component({
  selector: 'app-parametres',
  standalone: true,
  imports: [IonContent, IonIcon, RouterLink],
  templateUrl: './parametres.page.html',
  styleUrl: './parametres.page.scss',
})
export class ParametresPage {
  private readonly auth = inject(AuthService);

  readonly etablissement = this.auth.etablissementActif;

  private readonly rubriques: RubriqueParametres[] = [
    {
      titre: 'Paramètres classes',
      description: "Numérotation des classes (chiffres ou lettres) et nombre maximum d'élèves par classe.",
      icone: 'school-outline',
      teinte: 'cyan',
      route: '/tabs/parametres/classes',
      permission: 'classes.gerer',
    },
    {
      titre: 'Matières et coefficients',
      description: 'Matières enseignées par niveau, coefficients dans la moyenne, bilans lettres et sciences.',
      icone: 'book-outline',
      teinte: 'violet',
      route: '/tabs/parametres/matieres',
      permission: 'matieres.gerer',
    },
    {
      titre: 'Arrêt des notes',
      description: 'Réservé au directeur : arrêter les notes des classes et mettre fin à un trimestre ou un semestre.',
      icone: 'lock-closed-outline',
      teinte: 'rose',
      route: '/tabs/parametres/arret-notes',
      permission: 'notes.arreter',
    },
    {
      titre: 'Paramètres inscriptions',
      description: "Formats du matricule, inscription en ligne (site de l'État) et affichage des élèves dans leur classe.",
      icone: 'person-add-outline',
      teinte: 'vert',
      route: '/tabs/parametres/inscriptions',
      permission: 'etablissement.gerer',
    },
    {
      titre: 'Paramètres paiements',
      description: "Montants d'inscription par niveau (affectés / non affectés), types de frais et de réductions.",
      icone: 'cash-outline',
      teinte: 'orange',
      route: '/tabs/parametres/paiements',
      permission: 'tarifs.gerer',
    },
    {
      titre: 'Ordre de paiement',
      description: "Quel frais est payé en premier quand un montant est encaissé, puis où va le reste du versement.",
      icone: 'swap-vertical-outline',
      teinte: 'vert',
      route: '/tabs/parametres/ordre-paiement',
      permission: 'tarifs.gerer',
    },
    {
      titre: 'Établissement',
      description: 'Nom, logo, coordonnées et informations imprimées sur les documents.',
      icone: 'business-outline',
      teinte: 'bleu',
      route: '/tabs/parametres/etablissement',
      permission: 'etablissement.gerer',
    },
    {
      titre: 'Années scolaires',
      description: 'Ouverture et clôture des années, trimestres ou semestres.',
      icone: 'calendar-outline',
      teinte: 'violet',
      route: '/tabs/parametres/annees',
      permission: 'annees_scolaires.gerer',
    },
    {
      titre: 'SMS et notifications',
      description: 'SMS aux parents : expéditeur, crédit, rechargements et historique des envois.',
      icone: 'chatbubbles-outline',
      teinte: 'rose',
      route: '/tabs/parametres/sms',
      permission: 'sms.gerer',
    },
  ];

  readonly visibles = computed(() => {
    this.auth.poste();
    return this.rubriques.filter((r) => this.auth.aPermission(r.permission));
  });

  constructor() {
    addIcons({
      schoolOutline,
      personAddOutline,
      cashOutline,
      businessOutline,
      calendarOutline,
      chatbubblesOutline,
      chevronForwardOutline,
      bookOutline,
      lockClosedOutline,
      swapVerticalOutline,
    });
  }
}
