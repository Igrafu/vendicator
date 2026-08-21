"""Export a Claude Code session transcript to a readable PDF.

Reads the session .jsonl, keeps the conversation (user prompts + assistant
replies), and renders tool activity as compact one-line entries rather than
dumping raw output - so the result reads like a conversation record instead
of a log file.

Usage:
  .venv/bin/python model/src/export_chat_pdf.py <session.jsonl> <out.pdf>
"""
import json
import re
import sys
from datetime import datetime
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import (KeepTogether, PageBreak, Paragraph,
                                SimpleDocTemplate, Spacer, Table, TableStyle)

NAVY = colors.HexColor("#1B2A4A")
LIME = colors.HexColor("#6FA80A")
MINT = colors.HexColor("#12886A")
GREY = colors.HexColor("#6B7280")
LIGHT = colors.HexColor("#F4F6FA")

_styles = getSampleStyleSheet()
TITLE = ParagraphStyle("t", parent=_styles["Title"], textColor=colors.white,
                       fontSize=22, leading=26, alignment=TA_LEFT)
SUBTITLE = ParagraphStyle("st", parent=_styles["Normal"], fontSize=9.5,
                          textColor=colors.HexColor("#C6D2E6"))
WHO = ParagraphStyle("who", parent=_styles["Normal"], fontSize=8.5,
                     textColor=colors.white, spaceAfter=0)
BODY = ParagraphStyle("b", parent=_styles["Normal"], fontSize=9.3, leading=13,
                      textColor=colors.HexColor("#1A1A1A"))
CODE = ParagraphStyle("c", parent=BODY, fontName="Courier", fontSize=7.8,
                      leading=10, textColor=colors.HexColor("#243040"),
                      backColor=colors.HexColor("#EEF1F6"),
                      borderPadding=4, spaceBefore=3, spaceAfter=3)
TOOLS = ParagraphStyle("tl", parent=_styles["Normal"], fontSize=8,
                       leading=11, textColor=GREY)


def esc(text):
    return (str(text).replace("&", "&amp;").replace("<", "&lt;")
            .replace(">", "&gt;"))


def md_to_para(text):
    """Very small markdown subset -> reportlab markup, split into blocks."""
    blocks = []
    for chunk in re.split(r"```", text):
        if not chunk.strip():
            continue
        if chunk.count("\n") and chunk.lstrip().startswith(("bash", "php",
                                                            "python", "json",
                                                            "js", "sh", "")) \
                and blocks and blocks[-1][0] == "code_next":
            pass
        blocks.append(("text", chunk))
    # simpler: alternate text / code by fence index
    parts = text.split("```")
    out = []
    for i, part in enumerate(parts):
        if not part.strip():
            continue
        if i % 2 == 1:                       # inside a fence
            body = part.split("\n", 1)[1] if "\n" in part else part
            out.append(("code", body.rstrip()))
        else:
            out.append(("text", part))
    return out


def inline(text):
    t = esc(text)
    t = re.sub(r"\*\*(.+?)\*\*", r"<b>\1</b>", t)
    t = re.sub(r"(?<!\*)\*([^*\n]+?)\*(?!\*)", r"<i>\1</i>", t)
    t = re.sub(r"`([^`]+?)`", r'<font face="Courier" size="8">\1</font>', t)
    t = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", r'<u>\1</u>', t)
    return t.replace("\n", "<br/>")


def bubble(role, when, blocks, width):
    """Speaker banner + flowing body. Kept as separate flowables so long
    turns split across pages instead of overflowing a table cell."""
    is_user = role == "user"
    banner = Table(
        [[Paragraph(f"<b>{'YOU' if is_user else 'CLAUDE'}</b>"
                    f"<font size='7'>   {when}</font>", WHO)]],
        colWidths=[width])
    banner.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), NAVY if is_user else MINT),
        ("LEFTPADDING", (0, 0), (-1, -1), 9),
        ("TOPPADDING", (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
    ]))
    flow = [banner, Spacer(1, 3)]
    body_style = ParagraphStyle(
        "bb", parent=BODY,
        backColor=colors.HexColor("#EEF3FB") if is_user else None,
        borderPadding=(6, 8, 6, 8) if is_user else 0,
        leftIndent=2, rightIndent=2)
    for kind, body in blocks:
        if kind == "code":
            for line in body.split("\n"):
                flow.append(Paragraph(esc(line) or "&nbsp;", CODE))
        elif kind == "tools":
            flow.append(Spacer(1, 2))
            flow.append(Paragraph(body, TOOLS))
        else:
            for para in [p for p in body.split("\n\n") if p.strip()]:
                flow.append(Paragraph(inline(para.strip()), body_style))
                flow.append(Spacer(1, 4))
    flow.append(Spacer(1, 5 * mm))
    return flow


def header_band(width, title, subtitle):
    t = Table([[Paragraph(title, TITLE)], [Paragraph(subtitle, SUBTITLE)]],
              colWidths=[width])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), NAVY),
        ("LEFTPADDING", (0, 0), (-1, -1), 14),
        ("TOPPADDING", (0, 0), (0, 0), 14),
        ("BOTTOMPADDING", (0, -1), (-1, -1), 12),
        ("LINEBELOW", (0, -1), (-1, -1), 3, LIME),
    ]))
    return t


def text_of(content):
    """Pull plain text out of a message content field."""
    if isinstance(content, str):
        return content, []
    text, tools = [], []
    for block in content or []:
        if not isinstance(block, dict):
            continue
        if block.get("type") == "text":
            text.append(block.get("text", ""))
        elif block.get("type") == "tool_use":
            name = block.get("name", "tool")
            inp = block.get("input") or {}
            hint = (inp.get("description") or inp.get("file_path")
                    or inp.get("command") or inp.get("query")
                    or inp.get("ability_name") or inp.get("url")
                    or inp.get("path") or "")
            hint = re.sub(r"\s+", " ", str(hint))[:90]
            tools.append(f"{name}: {hint}" if hint else name)
    return "\n\n".join(t for t in text if t.strip()), tools


def load(path):
    turns = []
    with open(path, errors="ignore") as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            try:
                rec = json.loads(line)
            except ValueError:
                continue
            if rec.get("type") not in ("user", "assistant"):
                continue
            msg = rec.get("message") or {}
            role = msg.get("role") or rec.get("type")
            body, tools = text_of(msg.get("content"))
            if rec.get("isMeta") or rec.get("isCompactSummary"):
                continue
            # skip pure tool-result user turns
            if role == "user" and not body.strip():
                continue
            if role == "user" and body.lstrip().startswith(
                    ("<system-reminder", "<local-command", "[Request interrupted")):
                body = re.sub(r"<system-reminder>.*?</system-reminder>", "",
                              body, flags=re.S).strip()
                if not body:
                    continue
            if not body.strip() and not tools:
                continue
            turns.append({"role": role, "text": body, "tools": tools,
                          "ts": rec.get("timestamp", "")})
    return turns


def merge(turns):
    """Fold consecutive assistant turns (tool loops) into single entries."""
    out = []
    for t in turns:
        if out and out[-1]["role"] == t["role"] == "assistant":
            if t["text"].strip():
                out[-1]["text"] += ("\n\n" if out[-1]["text"] else "") + t["text"]
            out[-1]["tools"] += t["tools"]
        else:
            out.append(dict(t))
    return out


def main():
    src = Path(sys.argv[1])
    dest = Path(sys.argv[2])
    turns = merge(load(src))
    page = A4
    margin = 16 * mm
    width = page[0] - 2 * margin
    doc = SimpleDocTemplate(str(dest), pagesize=page, leftMargin=margin,
                            rightMargin=margin, topMargin=margin,
                            bottomMargin=margin,
                            title="Vendicator - build conversation",
                            author="Claude Code")
    started = turns[0]["ts"][:10] if turns else ""
    ended = turns[-1]["ts"][:10] if turns else ""
    story = [header_band(
        width, "VENDICATOR",
        f"Build conversation transcript &middot; {len(turns)} exchanges "
        f"&middot; {started} to {ended} &middot; exported "
        f"{datetime.now().strftime('%d %B %Y, %H:%M')}"),
        Spacer(1, 7 * mm)]

    for t in turns:
        when = t["ts"][:16].replace("T", " ")
        blocks = md_to_para(t["text"]) if t["text"].strip() else []
        if t["tools"]:
            seen, uniq = set(), []
            for tool in t["tools"]:
                if tool not in seen:
                    seen.add(tool)
                    uniq.append(tool)
            listed = "<br/>".join("• " + esc(x) for x in uniq[:14])
            more = (f"<br/>… and {len(uniq) - 14} more actions"
                    if len(uniq) > 14 else "")
            blocks.append(("tools",
                           f"<i>Actions taken ({len(t['tools'])}):</i><br/>"
                           + listed + more))
        story += bubble(t["role"], when, blocks, width)

    doc.build(story)
    print(f"Wrote {dest} from {len(turns)} exchanges")


if __name__ == "__main__":
    main()
