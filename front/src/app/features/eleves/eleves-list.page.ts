import { Component, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import {
  IonHeader,
  IonToolbar,
  IonTitle,
  IonContent,
  IonSearchbar,
  IonList,
  IonItem,
  IonLabel,
  IonAvatar,
  IonInfiniteScroll,
  IonInfiniteScrollContent,
  IonBadge,
} from '@ionic/angular';
import { EleveService } from '../../core/services/eleve.service';
import { Eleve } from '../../core/models/eleve.model';

@Component({
  selector: 'app-eleves-list',
  standalone: true,
  imports: [
    CommonModule,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonContent,
    IonSearchbar,
    IonList,
    IonItem,
    IonLabel,
    IonAvatar,
    IonInfiniteScroll,
    IonInfiniteScrollContent,
    IonBadge,
  ],
  templateUrl: './eleves-list.page.html',
  styleUrl: './eleves-list.page.scss',
})
export class ElevesListPage implements OnInit {
  readonly eleves = signal<Eleve[]>([]);
  readonly recherche = signal('');
  private page = 1;
  private dernierePage = 1;

  constructor(private eleveService: EleveService) {}

  ngOnInit(): void {
    this.charger();
  }

  rechercher(valeur: string | null | undefined): void {
    this.recherche.set(valeur ?? '');
    this.page = 1;
    this.eleves.set([]);
    this.charger();
  }

  chargerPageSuivante(evenement: CustomEvent): void {
    if (this.page >= this.dernierePage) {
      (evenement.target as HTMLIonInfiniteScrollElement).complete();
      return;
    }
    this.page += 1;
    this.charger(evenement);
  }

  private charger(evenement?: CustomEvent): void {
    this.eleveService.liste(this.recherche(), this.page).subscribe((resultat) => {
      this.eleves.update((liste) => (this.page === 1 ? resultat.data : [...liste, ...resultat.data]));
      this.dernierePage = resultat.last_page;
      (evenement?.target as HTMLIonInfiniteScrollElement | undefined)?.complete();
    });
  }
}
