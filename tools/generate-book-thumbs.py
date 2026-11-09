#!/usr/bin/env python3
"""Make small JPEG thumbnails of the book cover PNGs for download-books.php.

Reads books/{Language}/*.png and books/{Language}/books/*.png (5MB+ each),
writes assets/book-thumbs/{Language}/{same basename}.jpg at 360px wide. Local-only;
re-run after adding books. Skips thumbnails that are already up to date.
"""
from pathlib import Path
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
SRC, DST = ROOT / "books", ROOT / "assets" / "book-thumbs"
WIDTH = 360

made = 0
for lang in sorted(p for p in SRC.iterdir() if p.is_dir()):
    out_dir = DST / lang.name
    out_dir.mkdir(parents=True, exist_ok=True)
    for png in sorted(list(lang.glob("*.png")) + list((lang / "books").glob("*.png"))):
        out = out_dir / (png.stem + ".jpg")
        if out.exists() and out.stat().st_mtime >= png.stat().st_mtime:
            continue
        im = Image.open(png).convert("RGB")
        im = im.resize((WIDTH, round(im.height * WIDTH / im.width)), Image.LANCZOS)
        im.save(out, "JPEG", quality=82, optimize=True, progressive=True)
        made += 1
print(f"{made} thumbnails written to {DST}")
