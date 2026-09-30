import { Component, input, output } from '@angular/core';
import { IonIcon } from '@ionic/angular';
import { addIcons } from 'ionicons';
import { checkmarkCircle, checkmarkCircleOutline, ellipseOutline, warningOutline } from 'ionicons/icons';
import { LIBELLES_DECISION, ResultatEnLigne } from '../../core/models/inscription.model';
import { Ecart, dateFr } from './en-ligne';

/**
 * Recu de l'inscription en ligne (site de l'Etat) et, si on le compare a
 * notre fiche, les differences a cocher. Le bouton qui reprend la selection
 * est fourni par la page (contenu projete).
 */
@Component({
  selector: 'app-recu-en-ligne',
  standalone: true,
  imports: [IonIcon],
  templateUrl: './recu-en-ligne.component.html',
  styleUrl: './recu-en-ligne.component.scss',
})
export class RecuEnLigneComponent {
  readonly resultat = input.required<ResultatEnLigne>();
  /** null : pas de comparaison avec notre fiche. */
  readonly ecarts = input<Ecart[] | null>(null);
  readonly choisis = input<Set<string>>(new Set());
  readonly basculer = output<string>();

  readonly libellesDecision = LIBELLES_DECISION;
  readonly dateFr = dateFr;

  constructor() {
    addIcons({ checkmarkCircle, checkmarkCircleOutline, ellipseOutline, warningOutline });
  }

  montant(valeur: number | null | undefined): string {
    return (valeur ?? 0).toLocaleString('fr-FR') + ' F';
  }
}
