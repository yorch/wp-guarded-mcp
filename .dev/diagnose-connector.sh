#!/bin/bash
# Work out why an MCP client cannot auto-configure against a site.
#
#   ./diagnose-connector.sh https://example.com
#   ./diagnose-connector.sh https://example.com/wp-json/mcp/v1/http
#
# "Couldn't determine the server settings" from a client means OAuth discovery failed,
# and the client will not say which step. This walks the same steps in order and stops
# at the first one that breaks, with what it means.
#
# Read-only: it fetches public discovery documents and sends no credentials.
set -u

BASE="${1:-}"
if [ -z "$BASE" ]; then
  echo "usage: $0 <site url, or the full MCP endpoint url>" >&2
  exit 2
fi

# Accept either the site root or the endpoint itself.
case "$BASE" in
  */wp-json/mcp/v1/http) ENDPOINT="$BASE"; ORIGIN="${BASE%/wp-json/mcp/v1/http}" ;;
  *) ORIGIN="${BASE%/}"; ENDPOINT="$ORIGIN/wp-json/mcp/v1/http" ;;
esac

pass=0; fail=0
ok()   { printf '  \033[32mOK\033[0m    %s\n' "$1"; pass=$((pass+1)); }
bad()  { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; fail=$((fail+1)); }
note() { printf '        %s\n' "$1"; }

echo "Origin:   $ORIGIN"
echo "Endpoint: $ENDPOINT"
echo

# ---------------------------------------------------------------- scheme
echo "1. Transport"
case "$ORIGIN" in
  https://*) ok "the site is on HTTPS" ;;
  http://localhost*|http://127.0.0.1*)
    ok "loopback over HTTP, which local clients usually allow"
    note "A hosted client such as the Claude web connector still requires a public HTTPS URL." ;;
  *)
    bad "the site is on plain HTTP"
    note "Remote MCP clients require HTTPS and will refuse to configure against http://."
    note "This alone is enough to produce \"couldn't determine the server settings\"." ;;
esac
echo

# ---------------------------------------------------------------- discovery
echo "2. Discovery documents"
probe() { # probe <label> <url> <required key>
  local body code
  code=$(curl -sS -L -m 20 -o /tmp/_disc.json -w '%{http_code}' "$2" 2>/dev/null || echo 000)
  if [ "$code" != "200" ]; then
    bad "$1 -> HTTP $code"
    [ "$code" = "404" ] && note "Nothing is served there. See the .well-known note below."
    return 1
  fi
  if ! python3 -c "import json,sys; d=json.load(open('/tmp/_disc.json')); sys.exit(0 if '$3' in d else 1)" 2>/dev/null; then
    bad "$1 -> 200 but not the expected document"
    note "Something else is answering that path. First bytes:"
    note "$(head -c 120 /tmp/_disc.json | tr -d '\n')"
    return 1
  fi
  ok "$1"
  return 0
}

PRM_OK=1
probe "protected-resource metadata at the host root" \
      "$ORIGIN/.well-known/oauth-protected-resource" "authorization_servers" || PRM_OK=0
probe "protected-resource metadata, RFC 9728 path form" \
      "$ORIGIN/.well-known/oauth-protected-resource/wp-json/mcp/v1/http" "authorization_servers" || true
probe "authorization-server metadata at the host root" \
      "$ORIGIN/.well-known/oauth-authorization-server" "token_endpoint" || true
probe "authorization-server metadata under the REST route" \
      "$ORIGIN/wp-json/mcp/v1/.well-known/oauth-authorization-server" "token_endpoint" || true

if [ "$PRM_OK" = "0" ]; then
  note ""
  note "If only the host-root paths fail, something is serving /.well-known/ before"
  note "WordPress sees it. A physical .well-known directory left by certbot, or an"
  note "nginx/Apache alias for ACME challenges, both do this. The fix is to let"
  note "/.well-known/oauth-* fall through to WordPress."
fi
echo

# ---------------------------------------------------------------- consistency
echo "3. Does the advertised URL match the real one"
if curl -sS -L -m 20 -o /tmp/_prm.json "$ORIGIN/.well-known/oauth-protected-resource" 2>/dev/null; then
  ADVERTISED=$(python3 -c "import json;print(json.load(open('/tmp/_prm.json')).get('resource',''))" 2>/dev/null || echo "")
  if [ -z "$ADVERTISED" ]; then
    bad "the document has no \"resource\" field"
  elif [ "$ADVERTISED" = "$ENDPOINT" ]; then
    ok "advertised resource matches the endpoint"
  else
    bad "advertised resource does not match"
    note "advertised: $ADVERTISED"
    note "actual:     $ENDPOINT"
    note ""
    note "This is the usual cause behind a reverse proxy or CDN. Every URL in the"
    note "discovery documents comes from WordPress's own site address, so if WordPress"
    note "believes it is on http:// or on a different host than visitors use, it"
    note "advertises that, and the client rejects the mismatch."
    note "Check Settings > General, and whether the proxy sets X-Forwarded-Proto."
  fi
fi
echo

# ---------------------------------------------------------------- endpoint
echo "4. The MCP endpoint itself"
CODE=$(curl -sS -L -m 20 -o /tmp/_mcp.json -w '%{http_code}' -X POST "$ENDPOINT" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' 2>/dev/null || echo 000)
case "$CODE" in
  401|403) ok "endpoint is reachable and demands authentication (HTTP $CODE), which is correct" ;;
  200)     ok "endpoint answered"
           note "Note it answered WITHOUT credentials. That is expected only if you are logged in via cookies." ;;
  404)     bad "endpoint returns 404"
           note "Either the plugin is not active, or permalinks are not set. Try ?rest_route=/mcp/v1/http instead." ;;
  000)     bad "could not connect at all" ;;
  *)       bad "endpoint returned HTTP $CODE"
           note "A security plugin or firewall in front of the REST API will do this." ;;
esac
echo

# ---------------------------------------------------------------- registration
echo "5. Dynamic client registration"
CODE=$(curl -sS -L -m 20 -o /tmp/_reg.json -w '%{http_code}' -X POST "$ORIGIN/wp-json/mcp/v1/oauth/register" \
  -H 'Content-Type: application/json' \
  -d '{"client_name":"connector diagnostic","redirect_uris":["https://claude.ai/api/mcp/auth_callback"]}' 2>/dev/null || echo 000)
if [ "$CODE" = "200" ] || [ "$CODE" = "201" ]; then
  ok "the site accepts client registration"
  note "A throwaway client row was created. Unapproved registrations are pruned automatically."
else
  bad "registration returned HTTP $CODE"
  note "A client cannot configure itself without this."
fi

echo
printf '  %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
