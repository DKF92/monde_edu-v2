{{-- Bilan financier global de l'annee : une section par tableau (RapportController::bilanFinancier). --}}
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Bilan financier global {{ $annee }}</title>
@include('pdf._style-caisse')
<style>
  @page { margin: 12mm 12mm 14mm; }
  h2 { font-size: 10pt; color: #1b2f5e; margin: 6mm 0 1mm; padding-bottom: 2pt; border-bottom: 1pt solid #d9deea; page-break-after: avoid; }
  .note { font-size: 7.5pt; color: #56607a; margin: 0 0 1mm; }
  .section { page-break-inside: avoid; }
  .tab { width: 100%; border-collapse: collapse; margin-top: 1mm; }
  .tab th { background: #eef2fa; color: #1b2f5e; font-size: 7pt; text-transform: uppercase; padding: 3.5pt 3pt; border: 0.5pt solid #d9deea; white-space: nowrap; }
  .tab td { font-size: 7.5pt; padding: 3pt; border: 0.5pt solid #e3e7f0; white-space: nowrap; }
  .tab .g { text-align: left; } .tab .c { text-align: center; } .tab .d { text-align: right; }
  .tab .fort { font-weight: bold; }
  .tab .groupe td { background: #1b2f5e; color: #fff; font-weight: bold; }
  .tab .sous-total td { background: #f2f5fb; font-weight: bold; }
  .tab .total td { background: #d5dcef; font-weight: bold; }
  .tab tr { page-break-inside: avoid; }
</style>
</head>
<body>
  @include('pdf._entete-caisse', ['titreDocument' => 'BILAN FINANCIER GLOBAL'])
  <h1>Bilan financier global · {{ $annee }}</h1>
  <div class="sous-titre">Opérations financières de l'année scolaire · édité le {{ $genere_le->format('d/m/Y à H:i') }} par {{ $edite_par }}</div>

  @foreach ($sections as $i => $s)
    <div class="section">
      <h2>{{ $i + 1 }}. {{ $s['titre'] }}</h2>
      @if (!empty($s['rapport']['sous_titre']))<p class="note">{{ $s['rapport']['sous_titre'] }}</p>@endif
      @include('pdf._tableau-rapport', ['rapport' => $s['rapport']])
    </div>
  @endforeach
  <p class="note" style="margin-top: 4mm">Montants en F CFA. Dettes : années prises en compte dans Paramètres > Dettes.</p>
</body>
</html>
