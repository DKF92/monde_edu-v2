@php($m = fn ($v) => number_format((int) $v, 0, ',', "\u{202F}"))
@php($modes = ['especes' => 'Espèces', 'mobile_money' => 'Mobile money', 'cheque' => 'Chèque', 'virement' => 'Virement'])
@php($total = collect($lignes)->sum('montant'))
{{-- Liste des paiements (memes filtres que l'ecran Paiements), une ligne par paiement, puis totaux par mode. --}}
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Liste des paiements {{ $annee }}</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 10mm 10mm 12mm; }
  .liste { width: 100%; border-collapse: collapse; }
  .liste th { background: #eef2fa; color: #1b2f5e; font-size: 7pt; padding: 4pt 2pt; border: 0.5pt solid #d9deea; text-align: left; }
  .liste td { font-size: 7pt; padding: 3pt 2pt; border: 0.5pt solid #e3e7f0; white-space: nowrap; }
  .liste .d { text-align: right; }
  .liste .c { text-align: center; }
  .liste .total td { font-weight: bold; background: #d5dcef; }
  .liste tr { page-break-inside: avoid; }
  .modes { width: 50%; border-collapse: collapse; margin-top: 5mm; }
  .modes th, .modes td { font-size: 8pt; padding: 3pt 5pt; border: 0.5pt solid #e3e7f0; white-space: nowrap; }
  .modes th { background: #eef2fa; color: #1b2f5e; text-align: left; }
  .modes .d { text-align: right; }
  .modes .total td { font-weight: bold; background: #d5dcef; }
  .pied-doc { margin-top: 3mm; font-size: 7.5pt; color: #56607a; }
</style>
</head>
<body>
  @include('pdf._entete-caisse', ['titreDocument' => 'LISTE DES PAIEMENTS'])
  <h1>Paiements {{ $periode }}</h1>
  <div class="sous-titre">{{ count($lignes) }} paiement{{ count($lignes) > 1 ? 's' : '' }} · {{ $m($total) }} F CFA{{ $filtres ? ' · '.$filtres : '' }} · édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}</div>

  <table class="liste">
    <thead>
      <tr>
        <th class="c" style="width: 6mm">N°</th>
        <th style="width: 14mm">Date</th>
        <th style="width: 20mm">N° reçu</th>
        <th style="width: 15mm">Matricule</th>
        <th>Nom et prénoms</th>
        <th style="width: 16mm">Classe</th>
        <th style="width: 16mm">Mode</th>
        <th style="width: 27mm">Encaissé par</th>
        <th class="d" style="width: 15mm">{{ $part ? 'Part' : 'Montant' }}</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($lignes as $i => $l)
        <tr>
          <td class="c">{{ $i + 1 }}</td>
          <td>{{ \Carbon\Carbon::parse($l['date'])->format('d/m/Y') }}</td>
          <td>{{ $l['numero'] }}</td>
          <td>{{ $l['matricule'] }}</td>
          <td><strong>{{ $l['nom'] }}</strong> {{ \App\Support\Noms::ajuster((string) $l['nom'], $l['prenoms'], 54, 7, true) }}</td>
          <td>{{ $l['classe'] ?? '—' }}</td>
          <td>{{ $modes[$l['mode']] ?? $l['mode'] }}</td>
          <td>{{ $l['caissier'] ? \App\Support\Noms::prenoms($l['caissier'], 27.6, 7) : '—' }}</td>
          <td class="d">{{ $m($l['montant']) }}</td>
        </tr>
      @empty
        <tr><td colspan="9">Aucun paiement pour ces critères.</td></tr>
      @endforelse
      <tr class="total"><td colspan="8">Total</td><td class="d">{{ $m($total) }}</td></tr>
    </tbody>
  </table>

  @if (count($par_mode))
    <table class="modes">
      <tr><th>Mode de paiement</th><th class="d">Nombre</th><th class="d">Montant</th></tr>
      @foreach ($par_mode as $p)
        <tr><td>{{ $modes[$p['mode']] ?? $p['mode'] }}</td><td class="d">{{ $p['nombre'] }}</td><td class="d">{{ $m($p['montant']) }}</td></tr>
      @endforeach
      <tr class="total"><td>Total</td><td class="d">{{ count($lignes) }}</td><td class="d">{{ $m($total) }}</td></tr>
    </table>
  @endif
  <p class="pied-doc">Montants en F CFA.{{ $part ? ' Part = montant du paiement qui a réglé les frais choisis.' : '' }}</p>
</body>
</html>
