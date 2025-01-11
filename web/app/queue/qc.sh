#!/bin/bash
echo $(grep -o "$1" queue.txt| wc  -l)/$(wc -l queue.txt)
