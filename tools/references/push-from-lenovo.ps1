# Execute in Windows PowerShell on the Lenovo (not on the GTX machine).
# Transfers ONLY the eight expected historical aviation PDFs to Aiphonse via SCP.
# No OpenSSH server on the Lenovo is required: the Lenovo initiates the connection.
param(
    [string]$SourceDir = (Join-Path $env:USERPROFILE 'Downloads'),
    [string]$TargetHost = 'aiphonse@192.168.1.18'
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Get-Command ssh, scp -ErrorAction Stop | Out-Null
$books = @(
    @{ Id = 'crosby-fighters-bombers'; Pattern = 'The complete guide to fighters & bombers of the world*' },
    @{ Id = 'ege-balloons-aircraft'; Pattern = 'Pocket Encyclopaedia of World Aircraft_ Balloons and*' },
    @{ Id = 'munson-rotorcraft'; Pattern = 'Helicopters and other rotorcraft since 1907*' },
    @{ Id = 'munson-world-aircraft-1966'; Pattern = 'The Pocket Encyclopaedia of World Aircraft in Colour*' },
    @{ Id = 'munson-airliners-interwar'; Pattern = 'Encyclopedia of Airliners Between the Wars 1919 39*' },
    @{ Id = 'munson-airliners-since-1946'; Pattern = 'Airliners Since 1946*' },
    @{ Id = 'green-swanborough-fighters'; Pattern = 'The Complete Book of Fighters*' },
    @{ Id = 'sharpe-chant-fighting-aircraft'; Pattern = 'Fighting aircraft of the world*' }
)
$localFiles = @(Get-ChildItem -LiteralPath $SourceDir -Filter '*.pdf' -File)
$selected = @()
foreach ($book in $books) {
    $matches = @($localFiles | Where-Object { $_.Name -like $book.Pattern })
    if ($matches.Count -ne 1) {
        Write-Warning ("Expected one PDF for {0}; found {1}. Not copying an ambiguous file." -f $book.Id, $matches.Count)
        continue
    }
    $f = $matches[0]
    $selected += [pscustomobject]@{
        id = $book.Id
        source_filename = $f.Name
        remote_filename = "$($book.Id).pdf"
        size_bytes = $f.Length
        sha256 = (Get-FileHash -LiteralPath $f.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
        full_path = $f.FullName
    }
}
if ($selected.Count -eq 0) { throw 'No matching PDFs in Downloads. No remote action taken.' }
$remote = '.local/share/combat-sprite-simulator/reference-books'
& ssh $TargetHost "umask 077; mkdir -p ~/.local/share/combat-sprite-simulator/reference-books; chmod 700 ~/.local/share/combat-sprite-simulator ~/.local/share/combat-sprite-simulator/reference-books"
if ($LASTEXITCODE -ne 0) { throw 'SSH connection or remote mkdir failed.' }
foreach ($f in $selected) {
    Write-Host ("SCP: {0} -> {1}.pdf" -f $f.source_filename, $f.id)
    # Use safe remote filenames, because the local filenames contain apostrophes and Unicode.
    & scp -p $f.full_path "${TargetHost}:${remote}/$($f.remote_filename)"
    if ($LASTEXITCODE -ne 0) { throw "SCP failed for $($f.id)" }
}
$manifest = @($selected | Select-Object id, source_filename, remote_filename, size_bytes, sha256)
$tmp = Join-Path $env:TEMP ('combat-sprite-books-' + [guid]::NewGuid().ToString('N') + '.json')
try {
    $manifest | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $tmp -Encoding UTF8
    & scp $tmp "${TargetHost}:${remote}/manifest.json"
    if ($LASTEXITCODE -ne 0) { throw 'Manifest transfer failed.' }
} finally {
    Remove-Item -LiteralPath $tmp -ErrorAction SilentlyContinue
}
& ssh $TargetHost "chmod 600 ~/.local/share/combat-sprite-simulator/reference-books/*.pdf ~/.local/share/combat-sprite-simulator/reference-books/manifest.json"
if ($LASTEXITCODE -ne 0) { throw 'Could not set private file permissions.' }
Write-Host ("Done: transferred {0} of {1} expected PDFs to Aiphonse, outside Git." -f $selected.Count, $books.Count)
if ($selected.Count -lt $books.Count) { Write-Warning 'Some volumes were missing or ambiguous; inspect filenames and rerun.' }
