# Self-hosted webfonts

DM Sans and Jost, the families the BVite theme sets as `--body-font` and
`--title-font`. They are served from here rather than from Google Fonts because
the Content-Security-Policy is `style-src 'self'` / `font-src 'self' data:` with
no external origins - a remote `@import` is blocked, which left the theme falling
back to the system font stack. Self-hosting restores the intended typography
without opening the policy.

Both are variable fonts (weight axis), so one file per unicode-range subset
covers every weight the theme uses. Latin and latin-ext subsets are included;
the `@font-face` declarations are in `resources/css/scholarzim.css`.

- DM Sans - SIL Open Font License 1.1 - see OFL-DM-Sans.txt
- Jost    - SIL Open Font License 1.1 - see OFL-Jost.txt

Downloaded from Google Fonts (fonts.gstatic.com), v17 (DM Sans) / v20 (Jost).
