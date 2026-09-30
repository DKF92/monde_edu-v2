{{-- Liste des reductions de l'annee (menu Reductions). --}}
@php($m = fn ($v) => number_format((int) $v, 0, ',', "\u{202F}"))
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
  .liste .total td { background: #d5dcef; font-weight: bold; }
  .liste tr { page-break-inside: avoid; }
</style>
</head>
<body>
  @include('pdf._entete-caisse', ['titreDocument' => 'LISTE DES RÉDUCTIONS'])
  <h1>{{ $titre }}</h1>
  <div class="sous-titre">{{ $sous_titre ? $sous_titre.' · ' : '' }}{{ $lignes->count() }} réduction{{ $lignes->count() > 1 ? 's' : '' }} · édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}</div>
  <table class="liste">
    <tr><th class="c">N°</th><th>Matricule</th><th>Nom et prénoms</th><th>Classe</th><th class="c">Statut</th><th>Réduction</th><th class="d">Montant</th><th class="c">Date</th><th>Donneur d'ordre</th><th>Saisie par</th></tr>
    @forelse ($lignes as $i => $l)
      <tr>
        <td class="c">{{ $i + 1 }}</td>
        <td>{{ $l['matricule'] }}</td>
        <td>{{ $l['nom'] }} {{ $l['prenoms'] }}</td>
        <td>{{ $l['classe'] ?? $l['niveau'] }}</td>
        <td class="c">{{ $l['affecte'] ? 'AFF' : 'NAFF' }}</td>
        <td>{{ $l['type'] }}{{ $l['dette_annulee'] ? ' (annulée)' : '' }}</td>
        <td class="d">{{ $m($l['montant']) }} F</td>
        <td class="c">{{ \Carbon\Carbon::parse($l['date'])->format('d/m/Y') }}</td>
        <td>{{ $l['motif'] }}</td>
        <td>{{ $l['accorde_par'] }}</td>
      </tr>
    @empty
      <tr><td colspan="10" class="c">Aucune réduction.</td></tr>
    @endforelse
    <tr class="total"><td colspan="6">Total</td><td class="d">{{ $m($total) }} F</td><td colspan="3"></td></tr>
  </table>
</body>
</html>
