#!/usr/bin/env python3
"""Report whether a payload appears inside an executable <script> block.

Grepping for the payload string is not enough and gives a false positive: once
wp_kses_post strips the tag, the inner text survives as inert content, so a plain
grep still matches while nothing executes. This looks for the payload inside a real
script element instead. Prints "safe" or "executable".
"""
import re
import sys

payload = sys.argv[1]
html = sys.stdin.read()
blocks = re.findall(r"<script\b[^>]*>(.*?)</script>", html, re.S | re.I)
print("executable" if any(payload in b for b in blocks) else "safe")
