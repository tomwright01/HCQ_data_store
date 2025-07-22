#!/usr/bin/env python3
"""
anonymize_pdf.py

A small utility to redact identifiers in a PDF file.
Usage: anonymize_pdf.py input.pdf output.pdf
"""
import sys
import io
import re

import fitz  # PyMuPDF
from pdf2image import convert_from_bytes
from PIL import Image, ImageDraw
import pytesseract

# Regex for IDs to redact; adjust to your pattern
ID_RE = re.compile(r"\b[A-Z0-9]{4,}\b")


def anonymize_pdf_bytes(pdf_bytes: bytes) -> bytes:
    # Try text-layer based redaction
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
        return doc.write()  # redacted bytes

    # Fallback to OCR-based redaction on images
    pages = convert_from_bytes(pdf_bytes, dpi=200)
    redacted_pages = []
    for img in pages:
        draw = ImageDraw.Draw(img)
        data = pytesseract.image_to_data(img, output_type=pytesseract.Output.DICT)
        for i, word in enumerate(data["text"]):
            if ID_RE.match(word):
                x = data["left"][i]
                y = data["top"][i]
                w = data["width"][i]
                h = data["height"][i]
                draw.rectangle([x, y, x + w, y + h], fill="black")
        redacted_pages.append(img)

    # Assemble images back into PDF
    out_buf = io.BytesIO()
    redacted_pages[0].save(
        out_buf,
        format="PDF",
        save_all=True,
        append_images=redacted_pages[1:]
    )
    return out_buf.getvalue()


def main():
    if len(sys.argv) != 3:
        print("Usage: anonymize_pdf.py input.pdf output.pdf", file=sys.stderr)
        sys.exit(1)

    input_path = sys.argv[1]
    output_path = sys.argv[2]

    # Read input PDF
    with open(input_path, "rb") as f:
        pdf_bytes = f.read()

    try:
        redacted_bytes = anonymize_pdf_bytes(pdf_bytes)
        # Write out redacted PDF
        with open(output_path, "wb") as f_out:
            f_out.write(redacted_bytes)
    except Exception as e:
        print(f"Error during anonymization: {e}", file=sys.stderr)
        sys.exit(2)

    sys.exit(0)


if __name__ == "__main__":
    main()
