#!/usr/bin/env python3
"""Pull the confirmation token out of a refusal response.

Kept as a file rather than an inline `python3 -c` in the suite: the regex needs
both quote characters and backslashes, and neither survives being nested inside
a shell command substitution inside a double-quoted string. A mangled one-liner
silently yields an empty token, and the test then fails for the wrong reason.

Reads the JSON-RPC response on stdin. Optionally takes a dotted path into the
result text, e.g. "not_changed.new_admin_email", when the refusal is nested in a
JSON payload rather than being the whole message.
"""
import json
import re
import sys

doc = json.load(sys.stdin)

# A refusal can arrive as a JSON-RPC error or as a tool-level isError result.
if "error" in doc:
    text = doc["error"]["message"]
else:
    text = doc["result"]["content"][0]["text"]

if len(sys.argv) > 1:
    payload = json.loads(text)
    for key in sys.argv[1].split("."):
        payload = payload[key]
    text = payload

match = re.search(r'confirm set to "([0-9a-f]+)"', text)
print(match.group(1) if match else "")
