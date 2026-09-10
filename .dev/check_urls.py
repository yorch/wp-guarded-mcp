#!/usr/bin/env python3
"""Assert every absolute URL in a JSON document points at the expected host.

Kept as a file rather than an inline `python3 -c` in smoke.sh: the regex needs
quotes and backslashes that do not survive being nested inside a shell
command substitution, and a mangled one-liner fails the check for the wrong
reason. Reads the document on stdin, prints True or False.
"""
import json
import re
import sys

expected = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8080"
document = json.dumps(json.load(sys.stdin))
urls = re.findall(r'https?://[^"\s\\]+', document)
offsite = [u for u in urls if not u.startswith(expected)]
if offsite:
    print("False:", ", ".join(offsite))
else:
    print("True")
