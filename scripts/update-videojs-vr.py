#!/usr/bin/env python3
"""
Update videojs-vr in web/viewer/index.html from the videojs-vr dist folder.

This script replaces the content between // videojs-vr::start and // videojs-vr::end
with the latest minified videojs-vr build.

Usage:
    python scripts/update-videojs-vr.py

The script expects:
    - ../videojs-vr/dist/videojs-vr.min.js to exist
    - web/viewer/index.html to contain the markers
"""

import os
import re
import sys
from pathlib import Path

def get_project_root():
    """Get the project root directory (where this script is run from)."""
    script_dir = Path(__file__).resolve().parent
    return script_dir.parent

def update_videojs_vr():
    """Update videojs-vr in index.html with the latest build."""
    project_root = get_project_root()

    # Paths
    videojs_vr_dist = project_root.parent / 'videojs-vr' / 'dist' / 'videojs-vr.min.js'
    index_html = project_root / 'web' / 'viewer' / 'index.html'

    print(f"Project root: {project_root}")
    print(f"videojs-vr dist: {videojs_vr_dist}")
    print(f"index.html: {index_html}")

    # Check if source file exists
    if not videojs_vr_dist.exists():
        print(f"Error: videojs-vr.min.js not found at {videojs_vr_dist}")
        print("Please build videojs-vr first: cd ../videojs-vr && npm run build")
        sys.exit(1)

    # Check if target file exists
    if not index_html.exists():
        print(f"Error: index.html not found at {index_html}")
        sys.exit(1)

    # Read the new videojs-vr content
    print("Reading new videojs-vr.min.js...")
    with open(videojs_vr_dist, 'r', encoding='utf-8') as f:
        new_content = f.read().strip()

    print(f"New content size: {len(new_content)} bytes")

    # Read the current index.html
    print("Reading index.html...")
    with open(index_html, 'r', encoding='utf-8') as f:
        html_content = f.read()

    # Find the start and end markers
    start_marker = '// videojs-vr::start'
    end_marker = '// videojs-vr::end'

    start_idx = html_content.find(start_marker)
    if start_idx == -1:
        print(f"Error: Could not find '{start_marker}' marker in index.html")
        sys.exit(1)

    end_idx = html_content.find(end_marker)
    if end_idx == -1:
        print(f"Error: Could not find '{end_marker}' marker in index.html")
        sys.exit(1)

    # Find the end of the start marker line (include newline)
    start_line_end = html_content.find('\n', start_idx)
    if start_line_end == -1:
        start_line_end = start_idx + len(start_marker)
    else:
        start_line_end += 1  # Include the newline

    # Build new content using string concatenation (avoids regex escape issues)
    new_html_content = (
        html_content[:start_line_end] +
        new_content + '\n    ' +
        html_content[end_idx:]
    )

    # Check if replacement was made
    if new_html_content == html_content:
        print("Warning: No changes made. Content may already be up to date.")
    else:
        # Write the updated content
        print("Writing updated index.html...")
        with open(index_html, 'w', encoding='utf-8') as f:
            f.write(new_html_content)

        # Calculate size change
        old_size = len(html_content)
        new_size = len(new_html_content)
        diff = new_size - old_size
        sign = '+' if diff >= 0 else ''

        print(f"Successfully updated videojs-vr!")
        print(f"  Old size: {old_size:,} bytes")
        print(f"  New size: {new_size:,} bytes")
        print(f"  Change: {sign}{diff:,} bytes")

    return True

if __name__ == '__main__':
    try:
        update_videojs_vr()
    except Exception as e:
        print(f"Error: {e}")
        sys.exit(1)
