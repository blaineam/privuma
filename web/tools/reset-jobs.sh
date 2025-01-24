#!/bin/bash
SCRIPT_DIR=$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )
Modules=$(git config -f $SCRIPT_DIR/../../.gitmodules -l | awk '{split($0, a, /=/); split(a[1], b, /\./); print b[2]}' | uniq)
for Module in $Modules
do
    cd $SCRIPT_DIR/../../$Module
    git config --get remote.origin.url
    git reset --hard HEAD
    git fetch
    git pull origin main
    git status
done