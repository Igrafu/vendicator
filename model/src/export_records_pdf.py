"""Modern read-only PDF edition of the records: records/exports/records.pdf.

Same data as records.xlsx (accounts.json + model-history.jsonl) rendered as a
styled report: cover band, one section per category (Users, Subscriptions,
Rewards, Invoices, Predictions, Results, Model Performance) with the
subscription catalogue included. Encrypted with an owner password and
no-modify permissions -> viewable/printable everywhere, not editable.

Run:  .venv/bin/python model/src/export_records_pdf.py
"""
from datetime import datetime, timezone
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.pdfencrypt import StandardEncryption
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import (Paragraph, SimpleDocTemplate, Spacer, Table,
                                TableStyle)

from export_records import load_records
from game.rewards import SUBSCRIPTIONS

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "records" / "exports" / "records.pdf"

NAVY = colors.HexColor("#1B2A4A")
GREEN = colors.HexColor("#2ECC71")
STRIPE = colors.HexColor("#F4F6FA")
GREY = colors.HexColor("#6B7280")

styles = getSampleStyleSheet()
H1 = ParagraphStyle("h1", parent=styles["Title"], textColor=colors.white,
                    fontSize=24, leading=28, alignment=0)
SUB = ParagraphStyle("sub", parent=styles["Normal"],
                     textColor=colors.HexColor("#B9C4D8"), fontSize=10)
H2 = ParagraphStyle("h2", parent=styles["Heading2"], textColor=NAVY,
                    fontSize=14, spaceBefore=14, spaceAfter=4)
BODY = ParagraphStyle("body", parent=styles["Normal"], fontSize=8.5,
                      textColor=colors.HexColor("#222222"), leading=11)
NOTE = ParagraphStyle("note", parent=styles["Normal"], fontSize=8,
                      textColor=GREY)


def _p(text):
    return Paragraph("" if text is None else str(text), BODY)


def section_table(headers, rows, col_widths=None):
    data = [[Paragraph(f"<b>{h}</b>",
                       ParagraphStyle("th", parent=BODY,
                                      textColor=colors.white))
             for h in headers]]
    data += [[_p(c) for c in row] for row in rows] or \
            [[_p("no records yet")] + [_p("") for _ in headers[1:]]]
    t = Table(data, colWidths=col_widths, repeatRows=1)
    style = [
        ("BACKGROUND", (0, 0), (-1, 0), NAVY),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("TOPPADDING", (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
        ("LINEBELOW", (0, 0), (-1, 0), 1, GREEN),
        ("GRID", (0, 1), (-1, -1), 0.25, colors.HexColor("#D8DEE9")),
    ]
    for r in range(2, len(data), 2):
        style.append(("BACKGROUND", (0, r), (-1, r), STRIPE))
    t.setStyle(TableStyle(style))
    return t


def cover_band(width):
    stamp = datetime.now(timezone.utc).strftime("%d %B %Y, %H:%M UTC")
    t = Table([[Paragraph("VENDICATOR", H1)],
               [Paragraph("Records Report — users, subscriptions, rewards, "
                          f"invoices & model history · generated {stamp} · "
                          "read-only", SUB)]],
              colWidths=[width])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), NAVY),
        ("LEFTPADDING", (0, 0), (-1, -1), 14),
        ("TOPPADDING", (0, 0), (0, 0), 14),
        ("BOTTOMPADDING", (0, -1), (-1, -1), 12),
        ("LINEBELOW", (0, -1), (-1, -1), 2.5, GREEN),
    ]))
    return t


def build_story(accounts, history, width):
    users = accounts.get("users", [])
    subs = accounts.get("subscriptions", [])
    story = [cover_band(width), Spacer(1, 6 * mm)]

    story += [Paragraph("Users", H2), section_table(
        ["ID", "Username", "Tier", "Points", "Lifetime", "Rank",
         "Top 5 Players", "Top 5 Teams"],
        [[u.get("id"), u.get("username"), u.get("tier"),
          u.get("points_balance"), u.get("lifetime_points"), u.get("ranking"),
          ", ".join(u.get("top5_players", [])),
          ", ".join(u.get("top5_teams", []))] for u in users])]

    story += [Paragraph("Subscriptions", H2), section_table(
        ["ID", "User", "Tier", "Started", "Unlocked By", "Status"],
        [[s.get("subscription_id"), s.get("user_id"), s.get("tier"),
          s.get("started"), s.get("unlocked_by_points"), s.get("status")]
         for s in subs]),
        Spacer(1, 3 * mm),
        Paragraph("Subscription catalogue — ranks are unlocked with earned "
                  "points and buy better predictions, odds views and "
                  "customization:", NOTE),
        Spacer(1, 2 * mm),
        section_table(
            ["Tier", "Points Required", "Benefits"],
            [[tier.title(), f'{cfg["points_required"]:,}',
              " · ".join(cfg["benefits"])]
             for tier, cfg in SUBSCRIPTIONS.items()],
            col_widths=[width * 0.12, width * 0.16, width * 0.72])]

    story += [Paragraph("Rewards Redeemed", H2), section_table(
        ["User", "Reward", "Points", "Date", "Status"],
        [[u.get("username"), r.get("reward"), r.get("points"),
          r.get("date"), r.get("status")]
         for u in users for r in u.get("rewards_redeemed", [])])]

    story += [Paragraph("Invoices", H2), section_table(
        ["Invoice", "User", "Date", "Amount", "Currency", "Status"],
        [[i.get("invoice_id"), u.get("username"), i.get("date"),
          i.get("amount"), i.get("currency"), i.get("status")]
         for u in users for i in u.get("invoices", [])])]

    open_preds = [h for h in history if not h.get("settled")]
    story += [Paragraph("Open Predictions", H2), section_table(
        ["Timestamp", "League", "Match", "Model", "Market",
         "Probabilities %"],
        [[h.get("ts", "")[:16], h.get("league"),
          f"{h.get('home')} vs {h.get('away')}", h.get("model"),
          h.get("market"),
          ", ".join(f"{k} {v}" for k, v in (h.get("probs") or {}).items())]
         for h in open_preds])]

    settled = [h for h in history if h.get("settled")]
    story += [Paragraph("Settled Results", H2), section_table(
        ["Timestamp", "Match", "Model", "Market", "Result", "Brier", "CLV"],
        [[h.get("ts", "")[:16], f"{h.get('home')} vs {h.get('away')}",
          h.get("model"), h.get("market"), str(h.get("result")),
          (h.get("eval") or {}).get("brier"),
          (h.get("eval") or {}).get("clv")] for h in settled])]

    story += [Spacer(1, 6 * mm),
              Paragraph("Vendicator records are generated automatically from "
                        "accounts.json and model-history.jsonl. This document "
                        "is read-only; the source files are the record of "
                        "truth. Predictions are statistical analysis, not "
                        "betting advice.", NOTE)]
    return story


def main():
    accounts, history = load_records()
    OUT.parent.mkdir(parents=True, exist_ok=True)
    page = landscape(A4)
    margin = 14 * mm
    enc = StandardEncryption("", ownerPassword="vendicator-owner",
                             canPrint=1, canModify=0, canCopy=1,
                             canAnnotate=0)
    doc = SimpleDocTemplate(str(OUT), pagesize=page, encrypt=enc,
                            leftMargin=margin, rightMargin=margin,
                            topMargin=margin, bottomMargin=margin,
                            title="Vendicator Records",
                            author="Vendicator")
    doc.build(build_story(accounts, history, page[0] - 2 * margin))
    print(f"Wrote {OUT}")


if __name__ == "__main__":
    main()
