#!/usr/bin/env python3
"""
HISH Cursor Hook: Build Plugin
"""

import json
import sys


def main():
    """Process Cursor prompt event and inject build instructions."""
    try:
        # Read event from stdin
        event = json.load(sys.stdin)

        # Get original prompt
        orig = event.get("prompt", "")

        # Add build instruction
        new_prompt = "运行php和docker和mysql都必须通过mcp执行。 " + orig

        # Update event
        event["prompt"] = new_prompt

        # Output modified event
        json.dump(event, sys.stdout)

    except Exception:
        # On error, pass through original event unchanged
        try:
            if 'event' in locals():
                json.dump(event, sys.stdout)
            else:
                json.dump({}, sys.stdout)
        except Exception:
            json.dump({}, sys.stdout)


if __name__ == "__main__":
    main()