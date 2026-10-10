# Passage V1 -> V2 en ligne de commande (meme traitement que Parametres > Migration V1).
#
# 1. vide completement la base de migration et la recree (structure V2, postes, niveaux) ;
# 2. recharge les dumps V1 poses a la racine de back/ dans la base source ;
# 3. y reprend l'ecole V1 (+ recopie du compte -Compte comme Super admin) ;
# 4. si tout va bien, l'application (.env) utilise la base de migration.
#
# Usage (depuis back/) :  powershell -ExecutionPolicy Bypass -File scripts\migration-monde-edu.ps1 -Compte 1
# Essai sans rien garder ni activer :  ... -Essai
# La base cible ne doit pas etre celle du .env : remettre DB_DATABASE=monde_edu_v2 avant de relancer.

param(
    [string]$Cible = "migration_monde_edu",
    [int]$Compte = 0,
    [string]$Php = "C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe",
    [switch]$Essai
)

$ErrorActionPreference = "Stop"
Set-Location (Split-Path $PSScriptRoot -Parent)

# Dumps V1 a la racine de back/, le plus gros (base principale) d'abord
$dumps = Get-ChildItem -Path . -Filter *.sql -File | Sort-Object Length -Descending | ForEach-Object { $_.FullName }
if (-not $dumps) { throw "Aucun dump V1 (.sql) a la racine de back/." }

$options = @(
    "-d", "memory_limit=2G", "artisan", "migration:v1",
    "--cible=$Cible", "--preparer-cible",
    "--charger-source=$($dumps -join ',')"
)
$images = "storage\app\public\logos"
if (Test-Path $images) { $options += "--images=$((Resolve-Path $images).Path)" }
if ($Compte -gt 0) { $options += "--compte=$Compte" }
if ($Essai) { $options += "--essai" } else { $options += "--activer" }

& $Php @options
exit $LASTEXITCODE
