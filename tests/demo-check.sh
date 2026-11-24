#!/usr/bin/env bash
# CI: starts the demo image twice against PostgreSQL with the stand-in API, checks the demo
# accounts (created once, never duplicated, passwords never logged) and translates the sample
# article, the public ping and the version reports (sent to the stand-in, which stands in for the
# Commando Center). Needs: docker image supertext-craft-demo, PostgreSQL at $PG_URL (psql on the
# host), the stand-in on 127.0.0.1:8765.
set -euo pipefail
PORT=8080
export DEMO_ADMIN_EMAIL=ci-admin@example.com DEMO_ADMIN_PASSWORD="ci-$(openssl rand -hex 8)"
export DEMO_EDITOR_EMAIL=ci-editor@example.com DEMO_EDITOR_PASSWORD="ci-$(openssl rand -hex 8)"
export SUPERTEXT_API_KEY="ci-key-$(openssl rand -hex 8)"
sql() { psql "${PG_URL%/*}/craft" -tAc "$1"; }

start() {
	docker rm -f demo >/dev/null 2>&1 || true
	docker run -d --name demo --network host -e PORT=$PORT -e DATABASE_URL="$PG_URL" -e CRAFT_SECURITY_KEY=ci-security-key-ci-security-key \
		-e PRIMARY_SITE_URL=http://127.0.0.1:$PORT/ -e DEMO_ADMIN_EMAIL -e DEMO_ADMIN_PASSWORD -e DEMO_EDITOR_EMAIL -e DEMO_EDITOR_PASSWORD \
		-e SUPERTEXT_API_KEY -e SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/ -e SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report \
		supertext-craft-demo >/dev/null
	for _ in $(seq 120); do curl -sf -o /dev/null http://127.0.0.1:$PORT/admin/login && break; sleep 2; done
	curl -sf -o /dev/null http://127.0.0.1:$PORT/admin/login
	docker logs demo 2>&1 | grep '\[demo\]' || true
}

start
start   # second start: nothing duplicated or changed
docker logs demo 2>&1 | grep > /dev/null 'DEMO_EDITOR: account exists, left unchanged'
logs=$(docker logs demo 2>&1)
if grep -qF -e "$DEMO_ADMIN_PASSWORD" -e "$DEMO_EDITOR_PASSWORD" <<< "$logs"; then echo "A password appeared in the log"; exit 1; fi

test "$(sql "select string_agg(email, ',' order by email) from users")" = "ci-admin@example.com,ci-editor@example.com"
test "$(sql "select admin from users where email='ci-admin@example.com'")" = "t"
test "$(sql "select g.handle from users u join usergroups_users ug on ug.\"userId\"=u.id join usergroups g on g.id=ug.\"groupId\" where u.email='ci-editor@example.com'")" = "editors"

docker exec demo runuser -u www-data -- php craft supertext-translation/translate/check | tee /tmp/check.log
grep > /dev/null 'API key checked: the connection to Supertext works.' /tmp/check.log
grep > /dev/null 'Version report to Supertext: sent' /tmp/check.log

# Commando Center: the public ping (no login) and the reports, which never contain the key.
ping=$(curl -s "http://127.0.0.1:$PORT/actions/supertext-translation/ping?challenge=ci-challenge-0123456789")
test "$ping" = '{"ok":true,"plugin":"CraftCms-Supertext-Translation","challenge":"ci-challenge-0123456789"}'
test "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/actions/supertext-translation/ping?challenge=bad")" = 400
reports=$(curl -sf http://127.0.0.1:8765/reports)
grep -qF '"event":"installed"' <<< "$reports"
grep -qF '"event":"check"' <<< "$reports"
grep -qF '"plugin":{"name":"CraftCms-Supertext-Translation"' <<< "$reports"
grep -qF '"system":{"name":"Craft CMS","version":"5.' <<< "$reports"
grep -qF '"callback":{"url":"http://127.0.0.1:8080/actions/supertext-translation/ping"}' <<< "$reports"
if grep -qF "$SUPERTEXT_API_KEY" <<< "$reports$logs"; then echo "The API key appeared in a report or the log"; exit 1; fi
ENTRY=$(sql "select e.id from entries e join sections s on s.id=e.\"sectionId\" join elements el on el.id=e.id where s.handle='articles' and el.\"revisionId\" is null and el.\"draftId\" is null limit 1")
docker exec demo runuser -u www-data -- php craft supertext-translation/translate "$ENTRY" --from=en | tee /tmp/translate.log
test "$(grep -c ': translated' /tmp/translate.log)" = 3

test "$(sql "select es.title from elements_sites es join sites s on s.id=es.\"siteId\" where es.\"elementId\"=$ENTRY and s.handle='de'")" = "Schweizer Schokolade, weltweit versandt"
test "$(sql "select es.slug from elements_sites es join sites s on s.id=es.\"siteId\" where es.\"elementId\"=$ENTRY and s.handle='fr'")" = "chocolat-suisse-expedie-dans-le-monde-entier"
sql "select es.content::text from elements_sites es join sites s on s.id=es.\"siteId\" where es.\"elementId\"=$ENTRY and s.handle='de'" | grep > /dev/null '<strong>Berner</strong>'
# Nested Matrix entries are translated too.
sql "select es.title from elements_sites es join sites s on s.id=es.\"siteId\" join entries e on e.id=es.\"elementId\" where e.\"primaryOwnerId\"=$ENTRY and s.handle='de'" | grep > /dev/null 'Handgefertigt in Bern'

# A second run skips the sites that now have their own text.
docker exec demo runuser -u www-data -- php craft supertext-translation/translate "$ENTRY" --from=en | grep > /dev/null 'skipped'
curl -sf "http://127.0.0.1:$PORT/de/articles/schweizer-schokolade-weltweit-versandt" | grep > /dev/null 'Von Bern in die Welt'

# The other content: a page with an entry nested in CKEditor, a table and a link; a category; a global set; an image.
translate() { docker exec demo runuser -u www-data -- php craft supertext-translation/translate "$1" --from=en | tee /tmp/translate.log; test "$(grep -c ': translated' /tmp/translate.log)" = 3; }
de="(select id from sites where handle='de')"
PAGE=$(sql "select e.id from entries e join sections s on s.id=e.\"sectionId\" join elements el on el.id=e.id where s.handle='pages' and el.\"revisionId\" is null and el.\"draftId\" is null limit 1")
translate "$PAGE"
test "$(sql "select title from elements_sites where \"elementId\"=$PAGE and \"siteId\"=$de")" = "Über uns"
page_de=$(sql "select content::text from elements_sites where \"elementId\"=$PAGE and \"siteId\"=$de")
for text in 'Wir stellen seit drei Generationen' 'Läden in der Schweiz' 'Besuchen Sie unseren Shop' '<craft-entry data-entry-id='; do grep -qF "$text" <<< "$page_de"; done
# The nested Callout: a German entry of its own (the field is translated per site), referenced by the German HTML.
callout=$(sql "select e.id from entries e join elements_sites es on es.\"elementId\"=e.id join elements el on el.id=e.id where e.\"primaryOwnerId\"=$PAGE and es.\"siteId\"=$de and es.title='Kostenlose Lieferung' and el.\"dateDeleted\" is null")
grep -qF "data-entry-id=\\\"$callout\\\"" <<< "$page_de"
# Translating again with overwrite keeps the same German callout (no copies).
docker exec demo runuser -u www-data -- php craft supertext-translation/translate "$PAGE" --from=en --to=de --overwrite | grep > /dev/null 'translated'
test "$(sql "select count(*) from entries e join elements_sites es on es.\"elementId\"=e.id join elements el on el.id=e.id where e.\"primaryOwnerId\"=$PAGE and es.\"siteId\"=$de and el.\"dateDeleted\" is null and el.\"revisionId\" is null and el.\"draftId\" is null")" = 1
grep -qF "data-entry-id=\\\"$callout\\\"" <<< "$(sql "select content::text from elements_sites where \"elementId\"=$PAGE and \"siteId\"=$de")"

CATEGORY=$(sql "select id from categories limit 1")
translate "$CATEGORY"
test "$(sql "select title from elements_sites where \"elementId\"=$CATEGORY and \"siteId\"=$de")" = "Handgemachte Schokolade"
FOOTER=$(sql "select id from globalsets where handle='footer'")
translate "$FOOTER"
footer_de=$(sql "select content::text from elements_sites where \"elementId\"=$FOOTER and \"siteId\"=$de")
grep -qF 'Handgemacht in Bern seit 1952.' <<< "$footer_de"
grep -qF 'Schreiben Sie uns' <<< "$footer_de"
IMAGE=$(sql "select id from assets limit 1")
translate "$IMAGE"
test "$(sql "select title from elements_sites where \"elementId\"=$IMAGE and \"siteId\"=$de")" = "Pralinen auf einem Holztablett"
test "$(sql "select alt from assets_sites where \"assetId\"=$IMAGE and \"siteId\"=$de")" = "Sechs von Hand verzierte Pralinen auf einem Holztablett"
echo "Demo check passed"
