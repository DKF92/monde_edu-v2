<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Liste des classes {{ $annee }}</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 12mm 12mm 14mm; }
  .liste { width: 100%; border-collapse: collapse; }
  .liste th { background: #eef2fa; color: #1b2f5e; font-size: 7pt; text-transform: uppercase; padding: 4pt 3pt; text-align: center; border: 0.5pt solid #d9deea; }
  .liste th.g, .liste td.g { text-align: left; }
  .liste td { font-size: 8.5pt; padding: 3.5pt 3pt; border: 0.5pt solid #e3e7f0; text-align: center; }
  .liste .niveau td { background: #f2f5fb; font-weight: bold; color: #1b2f5e; }
  .liste .total td { background: #d5dcef; font-weight: bold; }
  .liste .complete { color: #b3261e; font-weight: bold; }
  .liste tr { page-break-inside: avoid; }
</style>
</head>
<body>
  @include('pdf._entete-caisse', ['titreDocument' => 'LISTE DES CLASSES'])

  <h1>Liste des classes {{ $annee }}</h1>
  <div class="sous-titre">
    {{ $totaux['classes'] }} classe{{ $totaux['classes'] > 1 ? 's' : '' }} · {{ $totaux['effectif'] }} élève{{ $totaux['effectif'] > 1 ? 's' : '' }} · édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}
  </div>

  <table class="liste">
    <thead>
      <tr>
        <th class="g">Classe</th>
        <th>Garçons</th>
        <th>Filles</th>
        <th>Effectif</th>
        <th>Limite</th>
        <th>Affectés</th>
        <th>Non affectés</th>
        <th>Redoublants</th>
        <th>Attente 1er versement</th>
        <th>Attente solde</th>
        <th>Soldés</th>
        <th class="g">Salle</th>
        <th class="g">LV2</th>
        <th class="g">Professeur principal</th>
        <th class="g">Éducateur</th>
      </tr>
    </thead>
    <tbody>
      @foreach (collect($classes)->groupBy('niveau.libelle') as $niveau => $groupe)
        @foreach ($groupe as $c)
          <tr>
            <td class="g"><strong>{{ $c['libelle'] }}</strong></td>
            <td>{{ $c['garcons'] }}</td>
            <td>{{ $c['filles'] }}</td>
            <td><strong>{{ $c['effectif'] }}</strong></td>
            <td class="{{ $c['complete'] ? 'complete' : '' }}">{{ $c['limite'] ?? '—' }}</td>
            <td>{{ $c['affectes'] }}</td>
            <td>{{ $c['non_affectes'] }}</td>
            <td>{{ $c['redoublants'] }}</td>
            <td>{{ $c['attente_versement'] }}</td>
            <td>{{ $c['attente_solde'] }}</td>
            <td>{{ $c['soldes'] }}</td>
            <td class="g">{{ $c['salle'] }}</td>
            <td class="g">{{ $c['langue_vivante_2'] }}</td>
            <td class="g">{{ $c['professeur_principal']['nom'] ?? '' }}</td>
            <td class="g">{{ $c['educateur']['nom'] ?? '' }}</td>
          </tr>
        @endforeach
        @if ($groupe->count() > 1)
          <tr class="niveau">
            <td class="g">Total {{ $niveau }}</td>
            <td>{{ $groupe->sum('garcons') }}</td>
            <td>{{ $groupe->sum('filles') }}</td>
            <td>{{ $groupe->sum('effectif') }}</td>
            <td></td>
            <td>{{ $groupe->sum('affectes') }}</td>
            <td>{{ $groupe->sum('non_affectes') }}</td>
            <td>{{ $groupe->sum('redoublants') }}</td>
            <td>{{ $groupe->sum('attente_versement') }}</td>
            <td>{{ $groupe->sum('attente_solde') }}</td>
            <td>{{ $groupe->sum('soldes') }}</td>
            <td colspan="4"></td>
          </tr>
        @endif
      @endforeach
      <tr class="total">
        <td class="g">Total établissement</td>
        <td>{{ $totaux['garcons'] }}</td>
        <td>{{ $totaux['filles'] }}</td>
        <td>{{ $totaux['effectif'] }}</td>
        <td></td>
        <td>{{ $totaux['affectes'] }}</td>
        <td>{{ $totaux['non_affectes'] }}</td>
        <td>{{ $totaux['redoublants'] }}</td>
        <td>{{ collect($classes)->sum('attente_versement') }}</td>
        <td>{{ collect($classes)->sum('attente_solde') }}</td>
        <td>{{ collect($classes)->sum('soldes') }}</td>
        <td colspan="4"></td>
      </tr>
    </tbody>
  </table>
</body>
</html>
