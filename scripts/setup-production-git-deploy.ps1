[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Invoke-Git {
    param(
        [Parameter(Mandatory = $true)]
        [string[]] $Arguments,
        [string] $WorkingDirectory = (Get-Location).Path
    )

    Push-Location $WorkingDirectory
    try {
        & git @Arguments 2>&1 | ForEach-Object { $_ }
        if ($LASTEXITCODE -ne 0) {
            throw "Git command failed: git $($Arguments -join ' ')"
        }
    } finally {
        Pop-Location
    }
}

function Read-ServerConfig {
    param([Parameter(Mandatory = $true)][string] $Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        throw "Missing .serverconfig at $Path"
    }

    $config = @{}
    foreach ($line in Get-Content -LiteralPath $Path) {
        if ([string]::IsNullOrWhiteSpace($line)) {
            continue
        }

        $parts = $line -split ':', 2
        if ($parts.Count -ne 2) {
            continue
        }

        $config[$parts[0].Trim()] = $parts[1].Trim()
    }

    foreach ($requiredKey in @('host', 'user', 'password', 'port')) {
        if (-not $config.ContainsKey($requiredKey) -or [string]::IsNullOrWhiteSpace($config[$requiredKey])) {
            throw "Missing '$requiredKey' in .serverconfig"
        }
    }

    return $config
}

function Invoke-RemoteCommand {
    param([Parameter(Mandatory = $true)][string] $Command)

    & $script:PlinkPath `
        -batch `
        -hostkey $script:HostKey `
        -P $script:ServerConfig['port'] `
        -l $script:ServerConfig['user'] `
        -pw $script:ServerConfig['password'] `
        $script:ServerConfig['host'] `
        $Command

    if ($LASTEXITCODE -ne 0) {
        throw "Remote command failed."
    }
}

function Copy-FileToServer {
    param(
        [Parameter(Mandatory = $true)][string] $LocalPath,
        [Parameter(Mandatory = $true)][string] $RemotePath
    )

    & $script:PscpPath `
        -batch `
        -hostkey $script:HostKey `
        -P $script:ServerConfig['port'] `
        -l $script:ServerConfig['user'] `
        -pw $script:ServerConfig['password'] `
        $LocalPath `
        "$($script:ServerConfig['host']):$RemotePath"

    if ($LASTEXITCODE -ne 0) {
        throw "Failed to copy file to server."
    }
}

$repoRootOutput = Invoke-Git -Arguments @('rev-parse', '--show-toplevel')
if ($repoRootOutput -is [System.Array]) {
    $repoRootOutput = $repoRootOutput[0]
}
$script:RepoRoot = (Resolve-Path ([string] $repoRootOutput)).Path
$script:ServerConfig = Read-ServerConfig -Path (Join-Path $script:RepoRoot '.serverconfig')
$script:PlinkPath = 'D:\programas\Putty\plink.exe'
$script:PscpPath = 'D:\programas\Putty\pscp.exe'
$script:HostKey = 'ssh-ed25519 255 SHA256:isu/1ILrdNVjwsCA05BF9GzHU1XwQVKlIu/+4DKoRfg'

$keyDir = Join-Path $env:USERPROFILE '.ssh'
$keyPath = Join-Path $keyDir 'id_ed25519_filesgel5_production'
$publicKeyPath = "$keyPath.pub"
$remoteRepoPath = "/home/$($script:ServerConfig['user'])/repos/files.gel5.com.git"
$remoteUrl = "ssh://$($script:ServerConfig['user'])@$($script:ServerConfig['host']):$($script:ServerConfig['port'])$remoteRepoPath"
$hookLocalPath = Join-Path $script:RepoRoot 'scripts\production-post-receive.sh'
$hookRemotePath = "$remoteRepoPath/hooks/post-receive"

if (-not (Test-Path -LiteralPath $keyDir)) {
    New-Item -ItemType Directory -Path $keyDir | Out-Null
}

if (-not (Test-Path -LiteralPath $keyPath)) {
    & 'C:\Windows\System32\OpenSSH\ssh-keygen.exe' @('-t', 'ed25519', '-f', $keyPath, '-N', '""', '-C', 'filesgel5-production-deploy')
    if ($LASTEXITCODE -ne 0) {
        throw 'Failed to create deploy SSH key.'
    }
}

$publicKey = (Get-Content -LiteralPath $publicKeyPath -Raw).Trim()
if ([string]::IsNullOrWhiteSpace($publicKey)) {
    throw 'Deploy public key is empty.'
}

Invoke-RemoteCommand "mkdir -p ~/.ssh && chmod 700 ~/.ssh && touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
Invoke-RemoteCommand "grep -qxF '$publicKey' ~/.ssh/authorized_keys || printf '%s\n' '$publicKey' >> ~/.ssh/authorized_keys"

Invoke-RemoteCommand "mkdir -p ~/repos && if [ ! -d '$remoteRepoPath' ]; then git init --bare '$remoteRepoPath'; fi"
Copy-FileToServer -LocalPath $hookLocalPath -RemotePath $hookRemotePath
Invoke-RemoteCommand "chmod +x '$hookRemotePath'"

$remoteNames = Invoke-Git -Arguments @('remote')
if ($remoteNames -notcontains 'production') {
    Invoke-Git -Arguments @('remote', 'add', 'production', $remoteUrl)
} else {
    Invoke-Git -Arguments @('remote', 'set-url', 'production', $remoteUrl)
}

Write-Host "Production Git deploy is configured."
Write-Host "Remote: production -> $remoteUrl"
Write-Host "SSH key: $keyPath"
