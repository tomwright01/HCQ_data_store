#!/usr/bin/env python3
"""
anonymize_pdf.py

A utility to redact patient identifiers in a PDF. It first attempts text‑layer redaction using PyMuPDF.
If no text matches, it falls back to OCR‑based redaction.

Usage:
    anonymize_pdf.py input.pdf output.pdf

Dependencies:
    pip install PyMuPDF pytesseract pdf2image pillow
    Ensure Tesseract OCR and Poppler are installed and in your PATH.
"""
import sys
import io
import re

import fitz  # PyMuPDF
from pdf2image import convert_from_bytes
from PIL import Image, ImageDraw
import pytesseract

# Regex for IDs to redact—adjust to your pattern
ID_RE = re.compile(r"\b[A-Z0-9]{4,}\b")

def anonymize_pdf_bytes(pdf_bytes: bytes) -> bytes:
    """
    Try text-layer redaction first; if nothing found,
    fall back to OCR redaction on page images.
    Returns the redacted PDF bytes.
    """
    doc = fitz.open(stream=pdf_bytes, filetype="pdf")
    found = False
    for page in doc:
        rects = page.search_for(ID_RE.pattern)
        if rects:
            found = True
            for r in rects:
                page.add_redact_annot(r, fill=(0, 0, 0))
    if found:
        doc.apply_redactions()
        return doc.write()

    # OCR fallback
    images = convert_from_bytes(pdf_bytes, dpi=200)
    redacted_images = []
    for img in images:
        draw = ImageDraw.Draw(img)
        data = pytesseract.image_to_data(img, output_type=pytesseract.Output.DICT)
        for i, text in enumerate(data["text"]):
            if ID_RE.match(text):
                x, y, w, h = (data[field][i] for field in ("left","top","width","height"))
                draw.rectangle([x, y, x + w, y + h], fill="black")
        redacted_images.append(img)

    buf = io.BytesIO()
    redacted_images[0].save(
        buf,
        format="PDF",
        save_all=True,
        append_images=redacted_images[1:]
    )
    return buf.getvalue()

def main():
    if len(sys.argv) != 3:
        print("Usage: anonymize_pdf.py input.pdf output.pdf", file=sys.stderr)
        sys.exit(1)

    in_path, out_path = sys.argv[1], sys.argv[2]
    with open(in_path, "rb") as f:
        data = f.read()

    try:
        redacted = anonymize_pdf_bytes(data)
        with open(out_path, "wb") as f_out:
            f_out.write(redacted)
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(2)

    sys.exit(0)

if __name__ == "__main__":
    main()
