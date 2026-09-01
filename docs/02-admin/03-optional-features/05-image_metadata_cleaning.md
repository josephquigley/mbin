# Image metadata cleaning with `exiftool`

It is possible to configure Mbin to remove meta-data from images.

**Docker deployments need nothing:** the image ships `exiftool`
(`libimage-exiftool-perl`), so the setting below is all that is required.

On a **bare metal** install, install `exiftool` yourself
(`libimage-exiftool-perl` on Ubuntu/Debian) and make sure the `exiftool`
executable exists and is visible in `PATH`.

If the binary is missing, `ExifCleaner` logs `exiftool binary was not found,
nothing will be done.` at **info** level and returns. Nothing errors and
nothing warns, so the setting below reads as configured while doing nothing at
all: on a production log level that discards info, the only symptom is that
uploaded images keep their metadata.

Available options in `.env`:

```bash
# available modes: none, sanitize, scrub
# can be set differently for user uploaded and external media
EXIF_CLEAN_MODE_UPLOADED=sanitize
EXIF_CLEAN_MODE_EXTERNAL=none
# path to exiftool binary, leave blank for auto PATH search
EXIF_EXIFTOOL_PATH=
# max execution time for exiftool in seconds, defaults to 10 seconds
EXIF_EXIFTOOL_TIMEOUT=10
```

Available cleaning modes are:

- `none`: no metadata cleaning occurs.
- `sanitize`: GPS and serial number metadata is removed. This is the default for uploaded images.
- `scrub`: most metadata is removed, except for the metadata required for proper image rendering
  and XMP IPTC attribution metadata.

More detailed information can [be found in the source-code](https://github.com/MbinOrg/mbin/blob/de20877d2d10e085bb35e1e1716ea393b7b8b9fc/src/Utils/ExifCleaner.php#L16) (for example look at `EXIFTOOL_ARGS_SCRUB`). Showing which arguments are passed to the `exiftool` CLI command.
