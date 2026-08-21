"""Canonical team naming - one registry the whole model uses.

Source feeds disagree ("Man United" / "Manchester Utd" / "Manchester United").
Everything user-facing goes through `canonical()` so the site always shows the
official club name, and `code()` gives the standard 3-letter abbreviation used
by the Premier League and UEFA (MUN, MCI, ARS...).

Add a club once here and every page, ticker and payload picks it up.
"""

# canonical name -> (official 3-letter code, country, aliases seen in feeds)
REGISTRY = {
    # --- England, Premier League ---
    "Arsenal": ("ARS", "England", ["Arsenal FC"]),
    "Aston Villa": ("AVL", "England", ["Villa"]),
    "Bournemouth": ("BOU", "England", ["AFC Bournemouth"]),
    "Brentford": ("BRE", "England", []),
    "Brighton & Hove Albion": ("BHA", "England", ["Brighton"]),
    "Burnley": ("BUR", "England", []),
    "Chelsea": ("CHE", "England", ["Chelsea FC"]),
    "Crystal Palace": ("CRY", "England", ["Palace"]),
    "Everton": ("EVE", "England", []),
    "Fulham": ("FUL", "England", []),
    "Ipswich Town": ("IPS", "England", ["Ipswich"]),
    "Leeds United": ("LEE", "England", ["Leeds"]),
    "Leicester City": ("LEI", "England", ["Leicester"]),
    "Liverpool": ("LIV", "England", ["Liverpool FC"]),
    "Luton Town": ("LUT", "England", ["Luton"]),
    "Manchester City": ("MCI", "England", ["Man City", "Manchester Cty"]),
    "Manchester United": ("MUN", "England",
                          ["Man United", "Man Utd", "Manchester Utd"]),
    "Newcastle United": ("NEW", "England", ["Newcastle"]),
    "Nottingham Forest": ("NFO", "England", ["Nott'm Forest", "Forest"]),
    "Sheffield United": ("SHU", "England", ["Sheffield Utd"]),
    "Southampton": ("SOU", "England", []),
    "Sunderland": ("SUN", "England", []),
    "Tottenham Hotspur": ("TOT", "England", ["Tottenham", "Spurs"]),
    "West Ham United": ("WHU", "England", ["West Ham"]),
    "Wolverhampton Wanderers": ("WOL", "England",
                                ["Wolves", "Wolverhampton"]),
    # --- England, EFL (selected) ---
    "Birmingham City": ("BIR", "England", ["Birmingham"]),
    "Blackburn Rovers": ("BLB", "England", ["Blackburn"]),
    "Bradford City": ("BRD", "England", ["Bradford"]),
    "Bristol City": ("BRC", "England", []),
    "Cardiff City": ("CAR", "England", ["Cardiff"]),
    "Charlton Athletic": ("CHL", "England", ["Charlton"]),
    "Coventry City": ("COV", "England", ["Coventry"]),
    "Derby County": ("DER", "England", ["Derby"]),
    "Hull City": ("HUL", "England", ["Hull"]),
    "Middlesbrough": ("MID", "England", ["Middlesboro"]),
    "Millwall": ("MIL", "England", []),
    "Norwich City": ("NOR", "England", ["Norwich"]),
    "Oxford United": ("OXF", "England", ["Oxford"]),
    "Portsmouth": ("POR", "England", []),
    "Preston North End": ("PNE", "England", ["Preston"]),
    "Queens Park Rangers": ("QPR", "England", ["QPR"]),
    "Sheffield Wednesday": ("SHW", "England", ["Sheffield Weds"]),
    "Stoke City": ("STK", "England", ["Stoke"]),
    "Swansea City": ("SWA", "England", ["Swansea"]),
    "Watford": ("WAT", "England", []),
    "West Bromwich Albion": ("WBA", "England", ["West Brom"]),
    "Wrexham": ("WRE", "England", []),
    # --- Spain ---
    "Athletic Bilbao": ("ATH", "Spain", ["Ath Bilbao", "Athletic Club"]),
    "Atletico Madrid": ("ATM", "Spain", ["Ath Madrid", "Atletico"]),
    "Barcelona": ("BAR", "Spain", ["FC Barcelona"]),
    "Celta Vigo": ("CEL", "Spain", ["Celta"]),
    "Deportivo Alaves": ("ALA", "Spain", ["Alaves"]),
    "Espanyol": ("ESP", "Spain", ["Espanol"]),
    "Getafe": ("GET", "Spain", []),
    "Girona": ("GIR", "Spain", []),
    "Mallorca": ("MLL", "Spain", []),
    "Osasuna": ("OSA", "Spain", []),
    "Rayo Vallecano": ("RAY", "Spain", ["Vallecano"]),
    "Real Betis": ("BET", "Spain", ["Betis"]),
    "Real Madrid": ("RMA", "Spain", []),
    "Real Sociedad": ("RSO", "Spain", ["Sociedad"]),
    "Sevilla": ("SEV", "Spain", []),
    "Valencia": ("VAL", "Spain", []),
    "Villarreal": ("VIL", "Spain", []),
    "Malaga": ("MAL", "Spain", []),
    "Elche": ("ELC", "Spain", []),
    "Levante": ("LEV", "Spain", []),
    "Oviedo": ("OVI", "Spain", ["Real Oviedo"]),
    # --- Italy ---
    "AC Milan": ("MIL", "Italy", ["Milan"]),
    "AS Roma": ("ROM", "Italy", ["Roma"]),
    "Atalanta": ("ATA", "Italy", []),
    "Bologna": ("BOL", "Italy", []),
    "Fiorentina": ("FIO", "Italy", []),
    "Inter Milan": ("INT", "Italy", ["Inter", "Internazionale"]),
    "Juventus": ("JUV", "Italy", []),
    "Lazio": ("LAZ", "Italy", []),
    "Napoli": ("NAP", "Italy", []),
    "Torino": ("TOR", "Italy", []),
    # --- Germany / France (selected) ---
    "Bayern Munich": ("BAY", "Germany", ["Bayern Munchen"]),
    "Bayer Leverkusen": ("B04", "Germany", ["Leverkusen"]),
    "Borussia Dortmund": ("BVB", "Germany", ["Dortmund"]),
    "RB Leipzig": ("RBL", "Germany", ["RasenBallsport Leipzig"]),
    "Paris Saint-Germain": ("PSG", "France", ["Paris SG", "PSG"]),
    "Marseille": ("OM", "France", []),
    "Lyon": ("OL", "France", []),
    "Monaco": ("MON", "France", []),
}

_ALIAS = {}
for _canon, (_code, _country, _aliases) in REGISTRY.items():
    _ALIAS[_canon.lower()] = _canon
    for _a in _aliases:
        _ALIAS[_a.lower()] = _canon


def canonical(name):
    """Feed name -> official club name (unchanged if unknown)."""
    if not name:
        return name
    return _ALIAS.get(str(name).strip().lower(), str(name).strip())


def code(name):
    """Official 3-letter abbreviation, e.g. 'Man United' -> 'MUN'."""
    canon = canonical(name)
    if canon in REGISTRY:
        return REGISTRY[canon][0]
    words = [w for w in str(canon).replace("-", " ").split() if w]
    if len(words) >= 3:
        return "".join(w[0] for w in words[:3]).upper()
    return "".join(c for c in str(canon).upper() if c.isalpha())[:3]


def country(name):
    canon = canonical(name)
    return REGISTRY[canon][1] if canon in REGISTRY else "Other"


def as_dict():
    """Export for the front end: canonical -> {code, country}."""
    return {c: {"code": v[0], "country": v[1]}
            for c, v in REGISTRY.items()}
