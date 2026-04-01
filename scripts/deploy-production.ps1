[CmdletBinding()]
param(
    [string] $RemoteName = 'production',
    [string] $RemoteBranch = 'main',
    [switch] $SkipLint,
    [switch] $SkipBuild
)

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
        $output = & git @Arguments 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw "Git command failed: git $($Arguments -join ' ')`n$output"
        }

        return $output
    } finally {
        Pop-Location
    }
}

function Assert-CleanWorkingTree {
    $status = Invoke-Git -Arguments @('status', '--short')
    if ($null -eq $status) {
        return
    }

    if ($status -isnot [System.Array]) {
        $status = @([string] $status)
    }

    if ($status.Count -gt 0 -and -not [string]::IsNullOrWhiteSpace(($status -join ''))) {
        throw "Commit or stash your changes before deploying.`n$($status -join [Environment]::NewLine)"
    }
}

function Read-ServerConfig {
    param([Parameter(Mandatory = $true)][string] $Path)

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

    return $config
}

$repoRootOutput = Invoke-Git -Arguments @('rev-parse', '--show-toplevel')
if ($repoRootOutput -is [System.Array]) {
    $repoRootOutput = $repoRootOutput[0]
}
$script:RepoRoot = (Resolve-Path ([string] $repoRootOutput)).Path
$serverConfig = Read-ServerConfig -Path (Join-Path $script:RepoRoot '.serverconfig')
$deployKeyPath = Join-Path $env:USERPROFILE '.ssh\id_ed25519_filesgel5_production'
$viteCliPath = Join-Path $script:RepoRoot 'node_modules\vite\bin\vite.js'
$buildPath = Join-Path $script:RepoRoot 'public\build'
$tempPath = Join-Path ([System.IO.Path]::GetTempPath()) ("filesgel5-deploy-" + [System.Guid]::NewGuid().ToString('N'))
$headShortShaOutput = Invoke-Git -Arguments @('rev-parse', '--short', 'HEAD')
if ($headShortShaOutput -is [System.Array]) {
    $headShortShaOutput = $headShortShaOutput[0]
}
$headShortSha = ([string] $headShortShaOutput).Trim()
$deployMessage = "Deploy production from $headShortSha"

if (-not (Test-Path -LiteralPath $deployKeyPath)) {
    throw "Missing deploy SSH key at $deployKeyPath. Run scripts/setup-production-git-deploy.ps1 first."
}

Assert-CleanWorkingTree

if (-not $SkipLint) {
    & 'C:\Program Files\nodejs\npm.cmd' run lint
    if ($LASTEXITCODE -ne 0) {
        throw 'Type checking failed.'
    }
}

if (-not $SkipBuild) {
    if (Test-Path -LiteralPath $buildPath) {
        Remove-Item -LiteralPath $buildPath -Recurse -Force
    }

    & node $viteCliPath build
    if ($LASTEXITCODE -ne 0) {
        throw 'Frontend build failed.'
    }
}

if (-not (Test-Path -LiteralPath $buildPath)) {
    throw 'Missing public/build after build step.'
}

Invoke-Git -Arguments @('remote', 'get-url', $RemoteName) | Out-Null

try {
    Invoke-Git -Arguments @('worktree', 'add', '--quiet', '--detach', $tempPath, 'HEAD')
    Copy-Item -LiteralPath $buildPath -Destination (Join-Path $tempPath 'public\build') -Recurse -Force

    Invoke-Git -Arguments @('add', '-f', 'public/build') -WorkingDirectory $tempPath | Out-Null
    Invoke-Git -Arguments @('commit', '-m', $deployMessage, '--no-verify') -WorkingDirectory $tempPath | Out-Null

    $env:GIT_SSH_COMMAND = "ssh -i `"$deployKeyPath`" -o StrictHostKeyChecking=accept-new"
    Invoke-Git -Arguments @('push', '--quiet', $RemoteName, "HEAD:refs/heads/$RemoteBranch", '--force') -WorkingDirectory $tempPath | Out-Null
    Write-Host "Production deploy completed from $headShortSha."
} finally {
    Remove-Item Env:\GIT_SSH_COMMAND -ErrorAction SilentlyContinue

    try {
        Invoke-Git -Arguments @('worktree', 'remove', '--quiet', $tempPath, '--force')
    } catch {
        if (Test-Path -LiteralPath $tempPath) {
            Remove-Item -LiteralPath $tempPath -Recurse -Force
        }
    }
}
