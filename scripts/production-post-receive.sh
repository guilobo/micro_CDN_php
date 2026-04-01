#!/usr/bin/env bash
set -euo pipefail

APP_DIR="$HOME/files.gel5.com"
GIT_DIR="$HOME/repos/files.gel5.com.git"
TARGET_BRANCH="refs/heads/main"

while read -r oldrev newrev refname; do
  if [[ "$refname" != "$TARGET_BRANCH" ]]; then
    continue
  fi

  mkdir -p "$APP_DIR/public/cdn"

  GIT_WORK_TREE="$APP_DIR" git --git-dir="$GIT_DIR" checkout -f main

  echo "Production deploy completed for $newrev"
done
