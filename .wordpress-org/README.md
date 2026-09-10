# Plugin directory assets

These files are for the wordpress.org listing. They are **not** part of the plugin.

## Where they go

The directory expects them in the `assets/` folder at the **top level** of the SVN
repository, beside `trunk/` and `tags/`, not inside either. Copy them across on release:

```
svn/
  assets/        <- these files
  trunk/         <- the plugin itself
  tags/1.0.0/
```

The folder is named `.wordpress-org` so that a leading dot keeps it out of the plugin
zip, which is the convention the common GitHub-to-SVN deploy actions expect.

## What is here

| File | Purpose |
|---|---|
| `icon.svg` | Source of truth for the mark. A shield with a keyhole: delegated access, held under guard. |
| `icon-128x128.png`, `icon-256x256.png` | Raster fallbacks. The directory requires a PNG even when an SVG is supplied, or the icon breaks in older browsers and on Facebook. |
| `banner.svg` | Source for both banners. |
| `banner-772x250.png`, `banner-1544x500.png` | Standard and retina banners. Must be PNG or JPG; SVG is not accepted here. |
| `screenshot-1.png` | The settings screen. |
| `screenshot-2.png` | The OAuth consent screen an administrator sees. |
| `screenshot-3.png` | Named keys, with the access level, tool list and expiry of each, above the activity history. |

Screenshot filenames must be lowercase and must match the numbered list in the
`== Screenshots ==` section of `readme.txt`, in order.

## Regenerating

The PNGs are rendered from the SVGs with headless Chrome rather than ImageMagick.
ImageMagick's own SVG renderer silently drops gradients and unstroked paths, and the
`rsvg-convert` delegate it would otherwise use is not installed here, so the fallback
produces a mangled image without reporting an error.

```sh
CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"

"$CHROME" --headless --disable-gpu --default-background-color=00000000 \
  --screenshot="$PWD/icon-256x256.png" --window-size=256,256 "file://$PWD/icon.svg"

"$CHROME" --headless --disable-gpu \
  --screenshot="$PWD/banner-772x250.png" --window-size=772,250 "file://$PWD/banner.svg"

# Retina: same source, rendered at 2x.
"$CHROME" --headless --disable-gpu --force-device-scale-factor=2 \
  --screenshot="$PWD/banner-1544x500.png" --window-size=772,250 "file://$PWD/banner.svg"
```

Note the retina line uses the same 772x250 window with a 2x scale factor rather than a
1544x500 window. The SVG carries its own dimensions, so a larger window renders it at its
natural size and leaves the rest of the canvas white, which is a silently wrong render of
exactly the kind this section warns about.

Always open the result and look at it. A silently wrong render is the normal failure
mode here, not an error message.

The screenshots were taken against the disposable site in `.dev/`, with the plugin
configured and a real OAuth grant approved, so they show actual output rather than
mockups.
