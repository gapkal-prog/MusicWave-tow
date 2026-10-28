# MusicWave Fonts

## Vazirmatn
- **Name:** Vazirmatn Variable
- **Version:** 33.003 (check via font metadata, see OFL.txt)
- **Source:** https://github.com/rastikerdar/vazirmatn
- **License:** SIL Open Font License 1.1 (OFL-1.1) — see OFL.txt
- **Path:** `Vazirmatn-Variable.woff2`
- **Usage:** Persian primary font, self-hosted via @font-face in `assets/css/tokens.css`, font-display: swap
- **SPDX:** OFL-1.1

## Latin Fonts (to be added in FIX-04)
- **Plus Jakarta Sans** (400-800) — latin subset variable woff2, OFL-1.1, from Google Fonts repo / fontsource
- **Syne** (600-800) — latin subset variable woff2, OFL-1.1
- Planned paths: `PlusJakartaSans-Variable.woff2`, `Syne-Variable.woff2` with respective OFL.txt files
- See `docs/third-party-notices.md` Web Fonts section

## Notes
- All fonts are GPL-2.0-or-later compatible (OFL-1.1 is compatible)
- No remote requests after FIX-04
- Keep font-family names stable so existing CSS tokens (`--mw-font-sans`, `--mw-font-display`) keep working
