#!/bin/bash
echo $(grep -n -o -m 1 -h -r -a "$1" queue.txt|cut -d: -f1)/$(wc -l queue.txt)
