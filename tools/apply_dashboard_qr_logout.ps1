param([string]$ProjectRoot = ".")
$ErrorActionPreference = "Stop"
$dashboard = Join-Path (Resolve-Path $ProjectRoot).Path "public\dashboard.php"
if(-not (Test-Path -LiteralPath $dashboard)){throw "Datei fehlt: $dashboard"}
$content=[IO.File]::ReadAllText($dashboard)
if($content.Contains('href="logout_qr.php"')){
    Write-Host "Logout-Link ist bereits vorhanden."
    exit 0
}
$button='<a class="button secondary dashboard-logout" href="logout_qr.php">Logout</a>'
if($content.Contains('</header>')){
    $content=$content.Replace('</header>',"    $button`r`n</header>")
}elseif($content.Contains('<body>')){
    $content=$content.Replace('<body>',"<body>`r`n<header class=`"dashboard-header-actions`">$button</header>")
}else{
    throw "Weder </header> noch <body> als sicherer Einfügepunkt gefunden. Datei wurde nicht verändert."
}
$utf8=New-Object Text.UTF8Encoding($false)
[IO.File]::WriteAllText($dashboard,$content,$utf8)
Write-Host "Logout-Link in public/dashboard.php ergänzt."
