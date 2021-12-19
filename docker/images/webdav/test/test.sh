#!/bin/bash -e

function log() {
    echo $* >&2
}

function die() {
    log $*
    exit 1
}

function xpath() {
    xmlstarlet sel -t -v "$*"
}

sleep 1;

SUT_USERNAME=admin
SUT_PASSWORD=admin
CURL_OPT="-s --digest -u $SUT_USERNAME:$SUT_PASSWORD"
SUT=http://sut

log "SUT should responds to HTTP requests"
curl $CURL_OPT -s $SUT -o /dev/null || die "SUT doesn't respond to http requests"

log "SUT should checks authorization"
curl -s -v $SUT 2>&1 | grep "401 Unauthorized" >/dev/null || die "SUT doesn't check authorization"

log "SUT should answer a PROPFIND"
curl $CURL_OPT -X PROPFIND $SUT | xpath "//d:status" | grep 200 >/dev/null || die "SUT didn't respond with a status 200 to PROPFIND"

pushd .
cd files

log "Generating a few small to large random files"
dd if=/dev/urandom of=random4m.dat bs=1024 count=$((4*1024))
dd if=/dev/urandom of=random8m.dat bs=1024 count=$((8*1024))
dd if=/dev/urandom of=random16m.dat bs=1024 count=$((16*1024))
dd if=/dev/urandom of=random32m.dat bs=1024 count=$((32*1024))
dd if=/dev/urandom of=random60m.dat bs=1024 count=$((60*1024))

MD5SUM_FILE=./md5sum
md5sum *.* >$MD5SUM_FILE
for FILE in *.*; do
    log "SUT should accept a PUT for $SUT/$FILE"
    curl $CURL_OPT -X PUT --data-binary "@$FILE" $SUT/$FILE || die "SUT didn't accept a put for a file"

    log "SUT should allow us to fetch $SUT/$FILE"
    curl $CURL_OPT $SUT/$FILE -o $FILE
done

log "SUT should give us identical files to what we PUT"
cat $MD5SUM_FILE
md5sum -c $MD5SUM_FILE

log "SUT should have at least as many files in it than we put up."
NUM_FILES=$(ls -1 *.* | wc -l)
NUM_FILES_IN_SUT=$(curl $CURL_OPT -X PROPFIND $SUT | xpath "//d:href" | wc -l)
(( NUM_FILES_IN_SUT >= NUM_FILES )) || die "SUT has less files than we put up. Expected: $NUM_FILES_IN_SUT >= $NUM_FILES"

log "All tests passed without failing."