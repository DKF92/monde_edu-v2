<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $b['titre'] }}</title>
@include('pdf._style-caisse')
</head>
<body>
  @include('pdf._entete-caisse')

  <h1>{{ $b['titre'] }}</h1>
  <div class="sous-titre">
    Encaissements de {{ $b['caissier']['nom'] }} · {{ $b['nombre_paiements'] }} paiement{{ $b['nombre_paiements'] > 1 ? 's' : '' }}
    · édité le {{ \Carbon\Carbon::parse($b['genere_le'])->format('d/m/Y à H:i') }}
  </div>

  @include('pdf._tableau-bilan', ['bilan' => $b])

  <table class="pied">
    <tr>
      <td class="signature" style="text-align: left"><span>Le caissier · {{ $b['caissier']['nom'] }}</span></td>
      <td class="signature"><span>Visa du responsable</span></td>
    </tr>
  </table>
</body>
</html>
