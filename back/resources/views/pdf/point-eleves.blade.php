{{-- Liste des eleves d'une case du point des inscrits. --}}
@php($m = fn ($v) => number_format((int) $v, 0, ',', "\u{202F}"))
@php($etats = \App\Support\Points::ETATS_INSCRIPTION)
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $titre }}</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 10mm 8mm 12mm; }
  .liste { width: 100%; border-collapse: collapse; }
  .liste th { background: #eef2fa; color: #1b2f5e; font-size: 7pt; text-transform: uppercase; padding: 3.5pt; border: 0.5pt solid #d9deea; text-align: left; }
  .liste td { font-size: 8pt; padding: 3pt; border: 0.5pt solid #e3e7f0; }
  .liste .d { text-align: right; } .liste .c { text-align: center; }
  .liste tr { page-break-inside: avoid; }
</style>
</head>
<body>
  @include('pdf._entete-caisse', ['titreDocument' => 'LISTE DES INSCRITS'])
  <h1>{{ $titre }}</h1>
  <div class="sous-titre">{{ $eleves->count() }} élève{{ $eleves->count() > 1 ? 's' : '' }} · édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}</div>
  <table class="liste">
    <tr><th class="c">N°</th><th>Matricule</th><th>Nom et prénoms</th><th class="c">Sexe</th><th>Classe</th><th class="c">Statut</th><th class="c">Inscrit le</th><th>Caisse</th><th class="d">Dû</th><th class="d">Payé</th><th class="d">Reste</th></tr>
    @foreach ($eleves as $i => $e)
      <tr>
        <td class="c">{{ $i + 1 }}</td>
        <td>{{ $e['matricule'] }}</td>
        <td>{{ $e['nom'] }} {{ $e['prenoms'] }}</td>
        <td class="c">{{ $e['sexe'] }}</td>
        <td>{{ $e['classe'] ?? $e['niveau'] }}</td>
        <td class="c">{{ $e['affecte'] ? 'AFF' : 'NAFF' }}</td>
        <td class="c">{{ \Carbon\Carbon::parse($e['date_inscription'])->format('d/m/Y') }}</td>
        <td>{{ $etats[$e['etat']] ?? '' }}</td>
        <td class="d">{{ $m($e['du']) }}</td>
        <td class="d">{{ $m($e['paye']) }}</td>
        <td class="d">{{ $m($e['reste']) }}</td>
      </tr>
    @endforeach
  </table>
</body>
</html>
