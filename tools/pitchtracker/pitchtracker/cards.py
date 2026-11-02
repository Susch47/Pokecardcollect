"""ME05 Pitch Black / Dunkelnacht – alle 120 Karten.

Deutsche Namen der Pokémon sind hinterlegt; Trainer-/Item-Namen werden beim Start
(sofern erreichbar) aus TCGdex nachgeladen und überschreiben diese Tabelle.
"""
from __future__ import annotations

SET_CODE = "ME05"
SET_TOTAL = 84

# Nummer, englischer Name, Seltenheit
_RAW = """\
1|Tropius|C
2|Grubbin|C
3|Fomantis|C
4|Lurantis ex|RR
5|Poltchageist|C
6|Sinistcha|U
7|Heatran|U
8|Mega Delphox ex|RR
9|Sizzlipede|C
10|Centiskorch|C
11|Charcadet|C
12|Armarouge|H
13|Goldeen|C
14|Seaking|U
15|Wailmer|C
16|Wailord ex|RR
17|Relicanth|U
18|Popplio|C
19|Brionne|C
20|Primarina|H
21|Finizen|C
22|Palafin|U
23|Electrike|C
24|Manectric|U
25|Charjabug|C
26|Vikavolt|U
27|Mega Zeraora ex|RR
28|Miraidon|H
29|Slowpoke|C
30|Slowbro|U
31|Mega Slowbro ex|RR
32|Jynx|C
33|Shuppet|C
34|Banette|C
35|Spiritomb|H
36|Litwick|C
37|Lampent|U
38|Mega Chandelure ex|RR
39|Dhelmise|U
40|Marshadow|U
41|Annihilape|U
42|Mankey|C
43|Primeape|C
44|Cranidos|C
45|Rampardos ex|RR
46|Drilbur|C
47|Koraidon|H
48|Mega Darkrai ex|RR
49|Vullaby|C
50|Mandibuzz|C
51|Inkay|C
52|Malamar|U
53|Nickit|C
54|Thievul|U
55|Morpeko ex|RR
56|Zarude|H
57|Maschiff|C
58|Mabosstiff|C
59|Chi-Yu|H
60|Skarmory|C
61|Shieldon|C
62|Bastiodon|H
63|Bronzor|C
64|Bronzong|U
65|Mega Excadrill ex|RR
66|Pikipek|C
67|Trumbeak|C
68|Toucannon|U
69|Type: Null|C
70|Silvally|H
71|Bombirdier|C
72|Antique Armor Fossil|C
73|Antique Skull Fossil|C
74|Backtrack Badge|U
75|Dark Bell|U
76|Fossil Quarry|U
77|Gladion's Final Battle|U
78|Gwynn|U
79|Jett|U
80|Misty's Vitality|U
81|Rust Syndicate Grunt|U
82|Tremendous Bomb|U
83|Shadowy Energy|H
84|Voltaic Energy|H
85|Fomantis|IR
86|Armarouge|IR
87|Goldeen|IR
88|Primarina|IR
89|Manectric|IR
90|Slowbro|IR
91|Dhelmise|IR
92|Thievul|IR
93|Bastiodon|IR
94|Toucannon|IR
95|Silvally|IR
96|Lurantis ex|UR
97|Wailord ex|UR
98|Mega Zeraora ex|UR
99|Mega Chandelure ex|UR
100|Rampardos ex|UR
101|Mega Darkrai ex|UR
102|Morpeko ex|UR
103|Mega Excadrill ex|UR
104|Brave Bangle|UR
105|Crushing Hammer|UR
106|Dark Bell|UR
107|Energy Switch|UR
108|Gladion's Final Battle|UR
109|Gwynn|UR
110|Iron Defender|UR
111|Misty's Vitality|UR
112|Rust Syndicate Grunt|UR
113|Tremendous Bomb|UR
114|Mega Zeraora ex|SIR
115|Mega Chandelure ex|SIR
116|Mega Darkrai ex|SIR
117|Morpeko ex|SIR
118|Gladion's Final Battle|SIR
119|Gwynn|SIR
120|Mega Darkrai ex|MHR"""

RARITY_LABEL = {"C": "Common", "U": "Uncommon", "H": "Holo Rare", "RR": "Double Rare",
                "IR": "Illustration Rare", "UR": "Ultra Rare (Full Art)",
                "SIR": "Special Illustration Rare", "MHR": "Mega Hyper Rare"}

# Deutsche Pokémon-Namen (Kernname ohne "Mega"/"ex")
DE_POKEMON = {
    "tropius": "tropius", "grubbin": "mabula", "fomantis": "imantis", "lurantis": "mantidea",
    "poltchageist": "mortcha", "sinistcha": "fatalitcha", "heatran": "heatran",
    "delphox": "fennexis", "sizzlipede": "thermopod", "centiskorch": "infernopod",
    "charcadet": "knarbon", "armarouge": "crimanzo", "goldeen": "goldini", "seaking": "golking",
    "wailmer": "wailmer", "wailord": "wailord", "relicanth": "relicanth", "popplio": "robball",
    "brionne": "marikeck", "primarina": "primarene", "finizen": "normifin",
    "palafin": "delfinator", "electrike": "frizelbliz", "manectric": "voltenso",
    "charjabug": "akkup", "vikavolt": "donarion", "zeraora": "zeraora", "miraidon": "miraidon",
    "slowpoke": "flegmon", "slowbro": "lahmus", "jynx": "rossana", "shuppet": "shuppet",
    "banette": "banette", "spiritomb": "kryppuk", "litwick": "lichtel", "lampent": "laternecto",
    "chandelure": "skelabra", "dhelmise": "moruda", "marshadow": "marshadow",
    "annihilape": "epitaff", "mankey": "menki", "primeape": "rasaff", "cranidos": "koknodon",
    "rampardos": "rameidon", "drilbur": "rotomurf", "excadrill": "stalobor",
    "koraidon": "koraidon", "darkrai": "darkrai", "vullaby": "skallyk",
    "mandibuzz": "grypheldis", "inkay": "iscalar", "malamar": "calamanero",
    "nickit": "kleptifux", "thievul": "gaunux", "morpeko": "morpeko", "zarude": "zarude",
    "maschiff": "mobtiff", "mabosstiff": "mastifioso", "chi-yu": "chi-yu",
    "skarmory": "panzaeron", "shieldon": "schilterus", "bastiodon": "bollterus",
    "bronzor": "bronzel", "bronzong": "bronzong", "pikipek": "peppeck",
    "trumbeak": "trompeck", "toucannon": "tukanon", "type: null": "typ:null",
    "silvally": "amigento", "bombirdier": "adebom",
}
# Namen, die zu allgemein sind, um ohne Kartennummer zu matchen
GENERIC_NAMES = {"dark bell", "energy switch", "crushing hammer", "iron defender",
                 "brave bangle", "tremendous bomb", "fossil quarry", "jett",
                 "shadowy energy", "voltaic energy", "backtrack badge",
                 "antique armor fossil", "antique skull fossil"}


def core_name(name: str) -> str:
    n = name.lower().replace("’", "'").strip()
    if n.startswith("mega "):
        n = n[5:]
    if n.endswith(" ex"):
        n = n[:-3]
    return n.strip()


class Card(dict):
    """dict, damit es direkt als JSON ans Dashboard geht."""


def _build() -> list[Card]:
    cards = []
    for line in _RAW.splitlines():
        num, en, rar = line.split("|")
        core = core_name(en)
        de_core = DE_POKEMON.get(core)
        de = None
        if de_core:
            de = ("Mega-" if en.startswith("Mega") else "") + de_core.title() + (
                "-ex" if en.endswith(" ex") else "")
        cards.append(Card(
            num=int(num), code=f"{int(num):03d}/084", en=en, de=de or en, rarity=rar,
            rarity_label=RARITY_LABEL[rar], core=core, core_de=de_core or "",
            mega=en.startswith("Mega"), ex=en.endswith(" ex"),
            secret=int(num) > SET_TOTAL, market=None))
    return cards


CARDS: list[Card] = _build()
BY_NUM = {c["num"]: c for c in CARDS}


def apply_tcgdex_names(de_names: dict[int, str]):
    """Deutsche Namen aus TCGdex übernehmen (z.B. Trainer, Items)."""
    for num, name in de_names.items():
        c = BY_NUM.get(num)
        if c and name:
            c["de"] = name
            cd = core_name(name)
            if cd and cd != c["core"]:
                c["core_de"] = cd
