#!/bin/bash
# WebDAV endpoint test script
# Run from inside php-web or php-cron container

PASS=0
FAIL=0
HOST="${WEBDAV_HOST:-https://localhost:8989}"
USER="${WEBDAV_USERNAME:-privuma}"
PASS_WD="${WEBDAV_PASSWORD:-}"

if [ -z "$PASS_WD" ]; then
    if [ -f /var/www/html/config/.env ]; then
        PASS_WD=$(grep '^WEBDAV_PASSWORD=' /var/www/html/config/.env | cut -d'=' -f2-)
        USER=$(grep '^WEBDAV_USERNAME=' /var/www/html/config/.env | cut -d'=' -f2-)
    fi
fi

TMPDIR=$(mktemp -d)
RCLONE_CONF="$TMPDIR/rclone.conf"
trap "rm -rf $TMPDIR" EXIT

cat > "$RCLONE_CONF" <<EOF
[privuma-webdav]
type = webdav
url = ${HOST}/access
vendor = other
user = ${USER}
pass = $(rclone obscure "$PASS_WD")
EOF

RCLONE="rclone --config $RCLONE_CONF --no-check-certificate"

pass() {
    echo "  PASS: $1"
    PASS=$((PASS + 1))
}

fail() {
    echo "  FAIL: $1"
    FAIL=$((FAIL + 1))
}

echo "=== Privuma WebDAV Tests ==="
echo ""

# Test 1: List root directories
echo "Test 1: List root directories"
ROOT_DIRS=$($RCLONE lsd privuma-webdav: 2>/dev/null) || true
if echo "$ROOT_DIRS" | grep -q "Albums" && echo "$ROOT_DIRS" | grep -q "Favorites" && echo "$ROOT_DIRS" | grep -q "Unfiltered"; then
    pass "Root contains Albums, Favorites, Unfiltered"
else
    fail "Root listing: $ROOT_DIRS"
fi

# Test 2: List top-level folders (should be grouped by --- prefix)
echo "Test 2: List Albums top-level folders"
TOP_DIRS=$($RCLONE lsd privuma-webdav:Albums/ 2>/dev/null) || true
if echo "$TOP_DIRS" | grep -q "Artists"; then
    DIR_COUNT=$(echo "$TOP_DIRS" | wc -l)
    pass "Albums has $DIR_COUNT top-level folders (Artists, etc.)"
else
    fail "Albums top-level: $TOP_DIRS"
fi

# Test 3: List nested subfolder (e.g. Albums/Artists/)
echo "Test 3: List nested subfolder"
SUB_DIRS=$($RCLONE lsd privuma-webdav:Albums/Artists/ 2>/dev/null) || true
if [ -n "$SUB_DIRS" ]; then
    SUB_COUNT=$(echo "$SUB_DIRS" | wc -l)
    pass "Albums/Artists/ has $SUB_COUNT subfolders"
else
    fail "Albums/Artists/ listing empty"
fi

# Test 4: List files in a leaf album
echo "Test 4: List files in a leaf album"
FIRST_ARTIST=$(echo "$SUB_DIRS" | head -1 | awk '{print $NF}')
if [ -n "$FIRST_ARTIST" ]; then
    FILES=$($RCLONE ls "privuma-webdav:Albums/Artists/$FIRST_ARTIST/" 2>/dev/null) || true
    if [ -n "$FILES" ]; then
        FILE_COUNT=$(echo "$FILES" | wc -l)
        pass "Albums/Artists/$FIRST_ARTIST/ has $FILE_COUNT files"
    else
        fail "Albums/Artists/$FIRST_ARTIST/ is empty"
    fi
else
    fail "No artist subfolders found"
fi

# Test 5: Download a media file
echo "Test 5: Download a media file"
if [ -n "$FILES" ]; then
    # Find a non-json file
    MEDIA_FILE=$(echo "$FILES" | grep -v '\.json$' | head -1 | awk '{$1=""; print $0}' | sed 's/^ *//')
    if [ -n "$MEDIA_FILE" ]; then
        $RCLONE cat "privuma-webdav:Albums/Artists/$FIRST_ARTIST/$MEDIA_FILE" > "$TMPDIR/testfile" 2>/dev/null || true
        FSIZE=$(stat -c%s "$TMPDIR/testfile" 2>/dev/null || stat -f%z "$TMPDIR/testfile" 2>/dev/null || echo "0")
        if [ "$FSIZE" -gt 0 ]; then
            pass "Downloaded '$MEDIA_FILE' ($FSIZE bytes)"
        else
            fail "Downloaded file is empty"
        fi
    else
        fail "No media file found to download"
    fi
else
    fail "Skipped - no files available"
fi

# Test 6: Read a JSON sidecar
echo "Test 6: Read JSON sidecar"
if [ -n "$FILES" ]; then
    JSON_FILE=$(echo "$FILES" | grep '\.json$' | head -1 | awk '{$1=""; print $0}' | sed 's/^ *//')
    if [ -n "$JSON_FILE" ]; then
        JSON_CONTENT=$($RCLONE cat "privuma-webdav:Albums/Artists/$FIRST_ARTIST/$JSON_FILE" 2>/dev/null) || true
        if echo "$JSON_CONTENT" | grep -q '"hash"'; then
            pass "JSON sidecar has hash field"
        else
            fail "JSON sidecar missing hash: $JSON_CONTENT"
        fi
    else
        fail "No JSON sidecar found"
    fi
else
    fail "Skipped - no files available"
fi

# Test 7: List Favorites
echo "Test 7: List Favorites directory"
FAVS=$($RCLONE lsd privuma-webdav:Favorites/ 2>/dev/null) || true
FAV_COUNT=$(echo "$FAVS" | grep -c '.' 2>/dev/null || echo "0")
pass "Favorites directory listed ($FAV_COUNT entries)"

# Test 8: Auth rejection
echo "Test 8: Authentication rejection"
BAD_CONF="$TMPDIR/bad_rclone.conf"
cat > "$BAD_CONF" <<EOF
[bad-webdav]
type = webdav
url = ${HOST}/access
vendor = other
user = wronguser
pass = $(rclone obscure "wrongpassword")
EOF

BAD_RESULT=$(rclone --config "$BAD_CONF" --no-check-certificate lsd bad-webdav: 2>&1) || true
if echo "$BAD_RESULT" | grep -qi "401\|unauthorized\|authentication\|error"; then
    pass "Bad credentials rejected"
else
    fail "Bad credentials not rejected: $BAD_RESULT"
fi

echo ""
echo "=== Results: $PASS passed, $FAIL failed ==="
exit $FAIL
