#!/bin/bash
#
# Bring a server's rest_api up to a given tree, file by file.
#
#   git archive origin/master | ssh <box> 'rm -rf /tmp/rest_api-src && mkdir /tmp/rest_api-src && tar -x -C /tmp/rest_api-src'
#   sudo bash /tmp/rest_api-src/deploy/sync-rest-api.sh /tmp/rest_api-src           # plan only
#   sudo bash /tmp/rest_api-src/deploy/sync-rest-api.sh /tmp/rest_api-src --apply   # do it
#
# Options:
#   --apply          change files (without it: print the plan and lint, change nothing)
#   --skip REGEX     leave paths matching this extended regex alone (repeatable)
#   --retire PATH    move a file that is no longer in the repo out of the tree,
#                    into the backup (repeatable)
#   --dst DIR        target tree (default /var/www/fusionpbx/app/rest_api)
#
# Only runtime code is deployed: actions/*, lib/*, top-level *.php, jobs/*.php and
# jobs/templates/*. docs/, migrations/, patches/, deploy/, tools/ and services/ stay
# out on purpose: they are applied by hand, and a .md or .sql in the web root can be
# downloaded.
#
# Why not git pull: deploys have always been file copies. On every box the
# checkout's HEAD is months old while the files are newer, and on CCL the tree
# belongs to www-data while .git belongs to telcobright. Comparing checksums is
# the only honest answer to "what is deployed here".
#
# Safety:
# - Every PHP file is linted first, with each PHP version nginx hands requests to,
#   before anything is touched.
# - Old copies go to /var/backups/rest_api-sync-<time>/, never into the web root.
# - Each file keeps its old owner and mode and is renamed into place in one step.
# - Checksums are checked afterwards, and a mismatch rolls the whole run back.
# - rollback.sh in the backup directory undoes the run.
set -euo pipefail

SRC=""; DST=/var/www/fusionpbx/app/rest_api; APPLY=0; SKIPS=(); RETIRE=()
while [ $# -gt 0 ]; do
    case "$1" in
        --apply) APPLY=1 ;;
        --skip) SKIPS+=("$2"); shift ;;
        --retire) RETIRE+=("$2"); shift ;;
        --dst) DST=$2; shift ;;
        -*) echo "unknown option $1"; exit 2 ;;
        *) SRC=${1%/} ;;
    esac
    shift
done

[ "$(id -u)" = 0 ] || { echo "run as root"; exit 2; }
[ -n "$SRC" ] && [ -f "$SRC/rest.php" ] || { echo "usage: $0 <extracted repo tree> [--apply] [--skip REGEX] [--retire PATH]"; exit 2; }
[ -f "$DST/rest.php" ] || { echo "$DST does not look like rest_api"; exit 2; }

sum() { md5sum < "$1" | cut -d' ' -f1; }
deployable() { [[ $1 =~ ^(actions|lib)/[^/]+$ || $1 =~ ^[^/]+\.php$ || $1 =~ ^jobs/[^/]+\.php$ || $1 =~ ^jobs/templates/[^/]+$ ]]; }
skipped() { local s; for s in "${SKIPS[@]+"${SKIPS[@]}"}"; do [[ $1 =~ $s ]] && return 0; done; return 1; }

# The PHP versions nginx actually runs (CCL has several php-fpm versions installed).
PHPS=()
for v in $(grep -rhoE 'php[0-9]+\.[0-9]+-fpm' /etc/nginx/sites-enabled/ 2>/dev/null | sort -u | sed 's/-fpm//'); do
    command -v "$v" >/dev/null 2>&1 && PHPS+=("$v")
done
[ ${#PHPS[@]} -gt 0 ] || PHPS=(php)

UPDATE=(); ADD=(); HELD=()
while IFS= read -r -d '' f; do
    p=${f#"$SRC"/}
    deployable "$p" || continue
    if skipped "$p"; then
        { [ -f "$DST/$p" ] && [ "$(sum "$f")" = "$(sum "$DST/$p")" ]; } || HELD+=("$p")
        continue
    fi
    if [ ! -e "$DST/$p" ]; then ADD+=("$p")
    elif [ "$(sum "$f")" != "$(sum "$DST/$p")" ]; then UPDATE+=("$p")
    fi
done < <(find "$SRC" -type f -print0 | sort -z)

for r in "${RETIRE[@]+"${RETIRE[@]}"}"; do
    [ -e "$DST/$r" ] || { echo "--retire $r: not in $DST"; exit 2; }
    [ ! -e "$SRC/$r" ] || { echo "--retire $r: still in the repo tree, refusing"; exit 2; }
done

echo "host:    $(hostname)  target $DST  php: ${PHPS[*]}"
echo "plan:    ${#UPDATE[@]} update, ${#ADD[@]} add, ${#RETIRE[@]} retire, ${#HELD[@]} differ but skipped"
for p in "${UPDATE[@]+"${UPDATE[@]}"}"; do echo "  update  $p"; done
for p in "${ADD[@]+"${ADD[@]}"}"; do echo "  add     $p"; done
for p in "${RETIRE[@]+"${RETIRE[@]}"}"; do echo "  retire  $p"; done
for p in "${HELD[@]+"${HELD[@]}"}"; do echo "  skipped $p"; done

fail=0
for p in "${UPDATE[@]+"${UPDATE[@]}"}" "${ADD[@]+"${ADD[@]}"}"; do
    case "$p" in
        *.php)
            for b in "${PHPS[@]}"; do
                "$b" -l "$SRC/$p" >/dev/null 2>&1 || { echo "LINT FAILED ($b): $p"; "$b" -l "$SRC/$p" 2>&1 | head -3; fail=1; }
            done ;;
        *.py)
            python3 -c 'import ast, sys; ast.parse(open(sys.argv[1]).read())' "$SRC/$p" 2>/dev/null || { echo "SYNTAX FAILED: $p"; fail=1; } ;;
    esac
done
[ $fail = 0 ] || { echo "lint failed -- nothing changed"; exit 1; }
echo "lint:    ok"

if [ $APPLY = 0 ]; then
    echo "plan only -- rerun with --apply to deploy"
    exit 0
fi
if [ $(( ${#UPDATE[@]} + ${#ADD[@]} + ${#RETIRE[@]} )) = 0 ]; then
    echo "already in sync -- nothing to do"
    exit 0
fi

B=/var/backups/rest_api-sync-$(date +%Y%m%d-%H%M%S)
mkdir -p "$B/files" "$B/retired"
chmod 700 "$B"
echo "$DST" > "$B/dst"
printf '%s\n' "${UPDATE[@]+"${UPDATE[@]}"}" > "$B/updated.txt"
printf '%s\n' "${ADD[@]+"${ADD[@]}"}" > "$B/added.txt"
printf '%s\n' "${RETIRE[@]+"${RETIRE[@]}"}" > "$B/retired.txt"
for p in "${UPDATE[@]+"${UPDATE[@]}"}"; do
    mkdir -p "$B/files/$(dirname "$p")"
    cp -a "$DST/$p" "$B/files/$p"
done
cat > "$B/rollback.sh" <<'ROLLBACK'
#!/bin/bash
# Undo one sync-rest-api.sh run: old files back, added files removed, retired files returned.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run as root"; exit 2; }
B=$(cd "$(dirname "$0")" && pwd); DST=$(cat "$B/dst")
while IFS= read -r p; do
    [ -n "$p" ] || continue
    t="$(dirname "$DST/$p")/.$(basename "$p").rollback"
    cp -a "$B/files/$p" "$t" && mv -f "$t" "$DST/$p"
done < "$B/updated.txt"
while IFS= read -r p; do [ -n "$p" ] && rm -f "$DST/$p"; done < "$B/added.txt"
while IFS= read -r p; do [ -n "$p" ] && mkdir -p "$(dirname "$DST/$p")" && mv -f "$B/retired/$p" "$DST/$p"; done < "$B/retired.txt"
echo "rolled back $DST from $B"
ROLLBACK
chmod 700 "$B/rollback.sh"

put() { # src dst owner group mode -- rename into place in one step
    local tmp; tmp="$(dirname "$2")/.$(basename "$2").syncnew"
    install -o "$3" -g "$4" -m "$5" "$1" "$tmp"
    mv -f "$tmp" "$2"
}

for p in "${UPDATE[@]+"${UPDATE[@]}"}"; do
    put "$SRC/$p" "$DST/$p" "$(stat -c %U "$DST/$p")" "$(stat -c %G "$DST/$p")" "$(stat -c %a "$DST/$p")"
done
for p in "${ADD[@]+"${ADD[@]}"}"; do
    d="$DST/$(dirname "$p")"
    [ -d "$d" ] || install -d -o "$(stat -c %U "$DST")" -g "$(stat -c %G "$DST")" -m "$(stat -c %a "$DST")" "$d"
    ref=$(find "$d" -maxdepth 1 -type f ! -name '.*' -print -quit)
    [ -n "$ref" ] || ref=$d
    mode=644; [ -x "$SRC/$p" ] && mode=755
    put "$SRC/$p" "$DST/$p" "$(stat -c %U "$ref")" "$(stat -c %G "$ref")" "$mode"
done
for r in "${RETIRE[@]+"${RETIRE[@]}"}"; do
    mkdir -p "$B/retired/$(dirname "$r")"
    mv "$DST/$r" "$B/retired/$r"
done

bad=0
for p in "${UPDATE[@]+"${UPDATE[@]}"}" "${ADD[@]+"${ADD[@]}"}"; do
    [ "$(sum "$DST/$p")" = "$(sum "$SRC/$p")" ] || { echo "CHECKSUM MISMATCH: $p"; bad=1; }
done
if [ $bad = 1 ]; then
    echo "rolling back"
    bash "$B/rollback.sh"
    exit 1
fi

echo "done:    ${#UPDATE[@]} updated, ${#ADD[@]} added, ${#RETIRE[@]} retired, checksums verified"
echo "backup:  $B"
echo "undo:    sudo bash $B/rollback.sh"
