import { Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { IonApp, IonRouterOutlet } from '@ionic/angular';
import { LoaderComponent } from './shared/loader.component';
import { InstallationComponent } from './shared/installation.component';
import { EtatReseauComponent } from './shared/etat-reseau.component';
import { MiseAJourApplicationService } from './shared/mise-a-jour-application.service';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, IonApp, IonRouterOutlet, LoaderComponent, InstallationComponent, EtatReseauComponent],
  templateUrl: './app.component.html',
})
export class AppComponent {
  constructor() {
    inject(MiseAJourApplicationService).demarrer();
  }
}
