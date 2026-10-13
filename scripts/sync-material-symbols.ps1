# Development-only asset synchronization; no build tooling is needed on hosting.
$ErrorActionPreference = 'Stop'
$materialRevision = '49d4db35df873165d6bd6ba09b063c7dafbac2f4'
$materialIcons = [ordered]@{
    'home' = 'home'; 'bookings' = 'receipt_long'; 'accounts' = 'account_balance_wallet'
    'categories' = 'category'; 'reports' = 'bar_chart'; 'closing' = 'lock'; 'audit' = 'history'
    'users' = 'group'; 'settings' = 'settings'; 'password' = 'key'; 'logout' = 'logout'
    'more' = 'more_vert'; 'chevron-up' = 'expand_less'; 'imports' = 'upload_file'; 'contacts' = 'contacts'
}
$materialDirectory = Join-Path $PSScriptRoot '../public/assets/vendor/material-symbols'
New-Item -ItemType Directory -Path $materialDirectory -Force | Out-Null
$materialSymbols = [Collections.Generic.List[string]]::new()
$materialReaderSettings = [Xml.XmlReaderSettings]::new()
$materialReaderSettings.DtdProcessing = [Xml.DtdProcessing]::Prohibit
$materialReaderSettings.XmlResolver = $null
foreach ($materialEntry in $materialIcons.GetEnumerator()) {
    $materialName = $materialEntry.Value
    $materialUrl = "https://raw.githubusercontent.com/google/material-design-icons/$materialRevision/symbols/web/$materialName/materialsymbolsrounded/${materialName}_24px.svg"
    $materialSvg = (Invoke-WebRequest -Uri $materialUrl).Content
    $materialReader = [Xml.XmlReader]::Create([IO.StringReader]::new($materialSvg), $materialReaderSettings)
    $materialDocument = [Xml.XmlDocument]::new()
    $materialDocument.XmlResolver = $null
    try { $materialDocument.Load($materialReader) } finally { $materialReader.Dispose() }
    $materialRoot = $materialDocument.DocumentElement
    if ($materialRoot.LocalName -ne 'svg' -or $materialRoot.GetAttribute('viewBox') -ne '0 -960 960 960') { throw "Unsupported SVG format: $materialName" }
    foreach ($materialPath in $materialRoot.ChildNodes) {
        if ($materialPath.LocalName -ne 'path') { throw "Unsupported SVG element: $materialName" }
        foreach ($materialAttribute in $materialPath.Attributes) {
            if ($materialAttribute.Name -notin @('d','fill-rule','clip-rule')) { throw "Unsupported SVG attribute: $materialName" }
        }
    }
    $materialSymbols.Add('<symbol id="' + $materialEntry.Key + '" viewBox="0 -960 960 960">' + $materialRoot.InnerXml + '</symbol>')
}
$materialSprite = '<svg xmlns="http://www.w3.org/2000/svg">' + "`n" + ($materialSymbols -join "`n") + "`n</svg>`n"
[IO.File]::WriteAllText((Join-Path $materialDirectory 'symbols.svg'), $materialSprite, [Text.UTF8Encoding]::new($false))
Invoke-WebRequest -Uri "https://raw.githubusercontent.com/google/material-design-icons/$materialRevision/LICENSE" -OutFile (Join-Path $materialDirectory 'LICENSE')
Write-Output ('Material Symbols Rounded: ' + $materialIcons.Count + ' icons synchronized at ' + $materialRevision)
