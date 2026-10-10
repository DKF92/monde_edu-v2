{{-- En-tete commun a tous les documents, sur le modele des documents V1 :
     a gauche la tutelle en petit (ministere, trait, direction regionale,
     « adresse / telephone »), a droite le logo, le nom de l'ecole et l'annee
     scolaire ; puis le titre du document. Donnees : Document::entete(). --}}
@php
  $tutelle = $tutelle ?? [];
  $anneeDoc = $annee ?? $b['annee'] ?? $anneeEntete ?? null;
  $contact = collect([$tutelle['adresse'] ?? null, $tutelle['telephone'] ?? null])->filter()->implode(' / ');
@endphp
  <table class="entete">
    <tr>
      <td class="tutelle">
        @if (!empty($tutelle['ministere']))<div class="ministere">{{ $tutelle['ministere'] }}</div><div class="trait">-------------------</div>@endif
        @if (!empty($tutelle['direction']))<div class="direction">{{ $tutelle['direction'] }}</div>@endif
        @if ($contact !== '')<div class="ligne-tutelle">{{ $contact }}</div>@endif
      </td>
      <td class="ecole">
        @if ($logo)<img class="logo" src="{{ $logo }}" alt=""><br>@endif
        <div class="nom-etab">{{ $etablissement->nom }}</div>
        @if ($anneeDoc)<div class="annee-doc">Année scolaire : {{ $anneeDoc }}</div>@endif
      </td>
    </tr>
  </table>
  <div class="bande"></div>
  {{-- Pied de chaque page (Document::pdf) : ecole et annee a gauche, pagination a droite. --}}
  <!--pied-document:{{ base64_encode($etablissement->nom.($anneeDoc ? ' · Année scolaire : '.$anneeDoc : '')) }}-->
  @if (!empty($titreDocument))
    <div class="titre-doc">{{ $titreDocument }}</div>
  @endif
