from __future__ import annotations

from pathlib import Path
import re
from html import escape
from datetime import datetime


ROOT = Path(__file__).resolve().parent
TEXT_PATH = ROOT / "menu-text.txt"
CSS_PATH = ROOT / "lapalma-menu-importer" / "assets" / "lapalma-menu.css"
OUTPUT_PATH = ROOT / "preview-menu.html"
PDF_PATTERN = re.compile(r"(\d{2})\.(\d{2})\.(\d{4})")


def clean_spaces(text: str) -> str:
    return re.sub(r"\s+", " ", text.strip())


def find_separator(title: str, sep: str) -> int | None:
    padded = f" {title} "
    idx = padded.lower().find(sep)
    if idx == -1:
        return None
    return idx - 1


def parse_item_line(line: str) -> dict:
    line = clean_spaces(line)
    price_match = re.search(r"€\s*([0-9]+(?:[.,][0-9]{2})?)", line)
    allergens_match = re.search(r"\s([A-Z](?:\.[A-Z])+\.?)\s*€", line)
    price = price_match.group(1) if price_match else ""
    allergens = allergens_match.group(1) if allergens_match else ""

    title = line
    if price:
        title = re.sub(r"\s*[A-Z](?:\.[A-Z])+\.?\s*€\s*[0-9]+(?:[.,][0-9]{2})?", "", title)
        title = re.sub(r"\s*€\s*[0-9]+(?:[.,][0-9]{2})?", "", title)
        title = title.strip()

    detail = ""
    if title:
        if "::" in title:
            parts = [part.strip() for part in title.split("::", 1)]
            title = parts[0] if parts else ""
            detail = parts[1] if len(parts) > 1 else ""
        else:
            best_pos = None
            best_sep = None
            for sep in (" mit ", " auf ", " in ", " con ", " vom ", " zur ", " zu ", " im ", " alla ", " al "):
                pos = find_separator(title, sep)
                if pos is not None and (best_pos is None or pos < best_pos):
                    best_pos = pos
                    best_sep = sep
            if best_pos is not None:
                detail = title[best_pos:].strip()
                title = title[:best_pos].strip()

    if detail:
        trailing = re.search(r"([A-Z](?:\.[A-Z])+\.?)$", detail.strip())
        if trailing:
            allergens = trailing.group(1)
            detail = detail[: trailing.start()].strip()

    price_formatted = ""
    if price:
        value = float(price.replace(",", "."))
        if abs(value - round(value)) < 0.0001:
            price_formatted = f"{int(round(value))} €"
        else:
            price_formatted = f"{value:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".") + " €"

    return {
        "title": title,
        "detail": detail,
        "price": price_formatted,
        "allergens": allergens,
    }


def parse_text(text: str) -> dict:
    lines = [clean_spaces(line) for line in text.splitlines()]
    lines = [line for line in lines if line]

    section_titles = {
        "Vorspeisen",
        "Nudelgerichte",
        "Hauptgerichte",
        "Dolci",
        "Desserts",
        "Antipasti",
        "Pasta",
        "Secondi",
    }

    sections: dict[str, list[dict]] = {}
    current_section: str | None = None
    buffer = ""
    legend_lines: list[str] = []

    for line in lines:
        line = normalize_text(line)
        if re.fullmatch(r"fi(\s+fi)*", line, flags=re.I):
            continue
        if re.search(r"--\s*\d+\s*of\s*\d+--", line, re.I):
            continue
        if re.match(r"^A\b.*:\s*", line) and "Glutenhaltiges" in line:
            legend_lines.append(line)
            continue
        if line in section_titles:
            if buffer:
                sections.setdefault(current_section or "Speisekarte", []).append(parse_item_line(buffer))
                buffer = ""
            current_section = line
            sections.setdefault(current_section, [])
            continue

        if current_section is None:
            current_section = "Speisekarte"
            sections.setdefault(current_section, [])

        buffer = line if not buffer else f"{buffer} {line}"
        if "€" in buffer:
            sections.setdefault(current_section, []).append(parse_item_line(buffer))
            buffer = ""

    if buffer and "€" in buffer:
        sections.setdefault(current_section or "Speisekarte", []).append(parse_item_line(buffer))

    return {"sections": sections, "legend": ""}


def normalize_text(text: str) -> str:
    return (
        text.replace("ﬁ", "fi")
        .replace("ﬂ", "fl")
        .replace("ﬀ", "ff")
        .replace("ﬃ", "ffi")
        .replace("ﬄ", "ffl")
    )


def extract_date_from_filename() -> str:
    for pdf in ROOT.glob("*.pdf"):
        match = PDF_PATTERN.search(pdf.name)
        if not match:
            continue
        day = int(match.group(1))
        month = int(match.group(2))
        year = int(match.group(3))
        months = {
            1: "Januar",
            2: "Februar",
            3: "März",
            4: "April",
            5: "Mai",
            6: "Juni",
            7: "Juli",
            8: "August",
            9: "September",
            10: "Oktober",
            11: "November",
            12: "Dezember",
        }
        if month not in months:
            continue
        return f"vom {day}. {months[month]} {year}"
    today = datetime.now()
    months = {
        1: "Januar",
        2: "Februar",
        3: "März",
        4: "April",
        5: "Mai",
        6: "Juni",
        7: "Juli",
        8: "August",
        9: "September",
        10: "Oktober",
        11: "November",
        12: "Dezember",
    }
    return f"vom {today.day}. {months[today.month]} {today.year}"


def render_html(data: dict, css: str, date: str) -> str:
    sections = data.get("sections", {})
    html = [
        "<!doctype html>",
        '<html lang="de">',
        "  <head>",
        '    <meta charset="utf-8">',
        "    <title>La Palma Speisekarte Vorschau</title>",
        "    <style>",
        css,
        "    </style>",
        "  </head>",
        "  <body>",
        '    <div class="lapalma-menu">',
    ]

    if date:
        html.append(f'      <div class="lapalma-menu-date">{escape(date)}</div>')

    for title, items in sections.items():
        html.append(f'      <h2 class="lapalma-menu-section-title">{escape(title)}</h2>')
        html.append('      <div class="lapalma-menu-section">')
        for item in items:
            html.append('        <div class="lapalma-menu-item">')
            html.append(f'          <div class="lapalma-menu-item-title">{escape(item["title"])}</div>')
            if item.get("detail"):
                html.append(f'          <div class="lapalma-menu-item-detail">{escape(item["detail"])}</div>')
            if item.get("allergens"):
                html.append(f'          <div class="lapalma-menu-item-allergens">{escape(item["allergens"])}</div>')
            if item.get("price"):
                html.append(f'          <div class="lapalma-menu-item-price">{escape(item["price"])}</div>')
            html.append("        </div>")
        html.append("      </div>")

    html.extend(["    </div>", "  </body>", "</html>"])
    return "\n".join(html)


def main() -> None:
    text = TEXT_PATH.read_text(encoding="utf-8")
    css = CSS_PATH.read_text(encoding="utf-8")
    data = parse_text(text)
    date = extract_date_from_filename()
    html = render_html(data, css, date)
    OUTPUT_PATH.write_text(html, encoding="utf-8")


if __name__ == "__main__":
    main()
