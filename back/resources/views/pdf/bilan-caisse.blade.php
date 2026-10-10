<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $b['titre'] }}</title>
@include('pdf._style-caisse')
<style>
  h2.caissier { font-size: 10.5pt; color: #1b2f5e; margin: 7mm 0 1mm; padding-bottom: 2pt; border-bottom: 1pt solid #d9deea; page-break-after: avoid; }
  .bloc { page-break-inside: avoid; }
</style>
</head>
<body>
  @include('pdf._entete-caisse')

  <h1>{{ $b['titre'] }}</h1>
  <div class="sous-titre">
    Encaissements de {{ $b['caissier']['nom'] }} · {{ $b['nombre_paiements'] }} paiement{{ $b['nombre_paiements'] > 1 ? 's' : '' }}
    · édité le {{ \Carbon\Carbon::parse($b['genere_le'])->format('d/m/Y à H:i') }}
  </div>

  @include('pdf._tableau-bilan', ['bilan' => $b])

  {{-- Filtre « Tous » : un bilan par caissier sous le total general. --}}
  @foreach ($b['par_caissier'] ?? [] as $c)
    <div class="bloc">
      <h2 class="caissier">Bilan de {{ $c['caissier']['nom'] }} · {{ $c['nombre_paiements'] }} paiement{{ $c['nombre_paiements'] > 1 ? 's' : '' }}</h2>
      @include('pdf._tableau-bilan', ['bilan' => $c])
    </div>
  @endforeach

  <table class="pied">
    <tr>
      <td class="signature" style="text-align: left"><span>{{ $b['caissier']['id'] ? 'Le caissier · '.$b['caissier']['nom'] : 'Établi par · '.$b['edite_par'] }}</span></td>
      <td class="signature"><span>Visa du responsable</span></td>
    </tr>
  </table>
</body>
</html>
