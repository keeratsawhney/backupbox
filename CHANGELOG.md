# Changelog

All changes since this repository was forked from [Little Backup Box](https://github.com/outdoorbits/little-backup-box).
Every entry is a merged pull request; the pull request has the full description.

## 2026-10-25

### Android app, Bluetooth, OLED
- **[#30](https://github.com/keeratsawhney/backupbox/pull/30) Backups report done right after copying; thumbnails and database in the background**
  - `background.py` makes the thumbnails and the database entries at low priority. The list of files to do is kept on the drive, so a power off only interrupts it.
  - The app's top bar shows a *Previews 312/2418* pill while it works.
  - Not used when the box powers off after the backup, a cloud backup follows, or EXIF updates are on: then everything runs before "done", as before.
  - Thumbnails in their own module (`lib_thumbnails.py`): JPEGs decoded at about 1/4 size (about 2× faster), turned while scaling.
  - Fix: converted HEIC images were not queued for thumbnails (wrong list name).
- **[#29](https://github.com/keeratsawhney/backupbox/pull/29) Fan at full speed from 60 °C** (Raspberry Pi 5 Active Cooler; the default reaches full speed at 75 °C): `install-fan.sh`, run by the installer and the update.
- **[#28](https://github.com/keeratsawhney/backupbox/pull/28) App finds the box on a home Wi-Fi during backups, faster thumbnails**
  - The app's box search uses a quick check that the box answers at once, also while a backup keeps it busy (the full status took too long, so the box was not found).
  - If https does not answer, the app opens the box over http on port 8080, like a browser (never after a refused certificate).
  - **Open box** over Bluetooth opens the box's address in the Wi-Fi network when it has no hotspot.
  - Thumbnails: one exiftool kept running per worker instead of two exiftool starts per RAW file; one ImageMagick thread per worker.
- **[#27](https://github.com/keeratsawhney/backupbox/pull/27) Faster "Sync database", RAW previews the right way up**
  - The database step after a backup reads photos in groups of 50 with one exiftool run, about 15× faster than one run per photo.
  - Quick previews of RAW files (shown while a backup still runs) are turned like the photo.
- **[#23](https://github.com/keeratsawhney/backupbox/pull/23) App over Bluetooth + Wi-Fi, box links open the app, OLED home screen fixes**
  - **Bluetooth service on the box** (`lib_ble.py`): status, start (copy only) and stop, power off, and the Wi-Fi details. It pairs with a code shown on the OLED.
  - **App:**
    - pairs through Android's device popup;
    - shows a status card over Bluetooth;
    - **Open box** joins the box's hotspot by itself.
  - **Links:** the box's address on Android opens the app, with the browser as fallback.
  - **OLED:**
    - every screen returns to the home screen (8 s; 60 s after a backup; never during one);
    - the home screen survives display restarts;
    - the touch UI viewer's download button keeps clear of the status bar.
- **[#22](https://github.com/keeratsawhney/backupbox/pull/22) Android app: Backup Box (touch UI in an app)**
  - Finds the box: last used, hotspot, network search, or a typed address.
  - Stays on the box's Wi-Fi without internet.
  - HTTPS with the box's certificate pinned; the password is remembered.
  - Downloads go to the gallery.
  - GitHub builds and publishes the APK; the signing key comes from secrets.
- **[#21](https://github.com/keeratsawhney/backupbox/pull/21) OLED: new menu, home screen and message design, copy-only backups**
  - Graphic menu with icons, selection bar, confirm dialogs and status page.
  - Instant redraw; joystick hold-to-scroll and hold-left to close.
  - Home screen with the backup drive's fill (read even when not mounted).
  - All messages under one title bar.
  - Fixes:
    - QR screens stacked "offline" lines;
    - the menu stayed on screen;
    - `conf_BACKUP_DEFAULT_MOVE_FILES` was missing.
- **[#20](https://github.com/keeratsawhney/backupbox/pull/20) Power saving, hotspot without forced sign-in, Wi-Fi settings in the app**
  - Lighter display loop and IP job; the OLED sleeps; the browser polls less.
  - The box's hotspot no longer forces comitup's page; `http://10.41.0.1` opens the box.
  - Wi-Fi settings in the app view: networks nearby, connect, forget.
  - The box's address opens the app view.

## 2026-10-24

### Display, previews, branding
- **[#19](https://github.com/keeratsawhney/backupbox/pull/19) Display: ready screen after the boot logo** (the logo stayed up because temporary IP screens returned to it).
- **[#18](https://github.com/keeratsawhney/backupbox/pull/18) Latest files ordered by when they were added; robust video previews.**
- **[#17](https://github.com/keeratsawhney/backupbox/pull/17) Faster thumbnails**
  - reduced JPEG decoding;
  - embedded RAW previews;
  - several files in parallel, fewer on weak power.
- **[#16](https://github.com/keeratsawhney/backupbox/pull/16) Your Wedding Saga logo, BACKUP BOX boot greeting, sage theme**
  - logo in all UIs and the favicon;
  - boot screen on the OLED;
  - "sage" theme.

### App view (touch UI)
- **[#15](https://github.com/keeratsawhney/backupbox/pull/15) System page:** IPv4 address only, long values wrap.
- **[#14](https://github.com/keeratsawhney/backupbox/pull/14) Drive panel per drive, long-press selection in Files**
  - the panel: use as backup drive, open, thumbnails, database, EXIF, rename, check, repair, format;
  - in Files: rename and delete.
- **[#13](https://github.com/keeratsawhney/backupbox/pull/13) Under-voltage as a pill in the top bar**, with a message you can dismiss.

## 2026-10-21

### Web interfaces and installer
- **[#12](https://github.com/keeratsawhney/backupbox/pull/12) Backup page:**
  - STOP shows only while a backup runs;
  - Options picker and a maintenance table per drive;
  - the gallery can open any drive (read-only, desktop automounts too).
- **[#11](https://github.com/keeratsawhney/backupbox/pull/11) Update button survives the update** and needs no password prompt.
- **[#10](https://github.com/keeratsawhney/backupbox/pull/10) Classic UI on phones;** source and target as compact pickers.
- **[#9](https://github.com/keeratsawhney/backupbox/pull/9) Photos:** plain drive names; the card button shows only when a card is plugged in.
- **[#8](https://github.com/keeratsawhney/backupbox/pull/8) Installer:**
  - fixes steps that failed without root;
  - no longer prints the TLS key.
- **[#7](https://github.com/keeratsawhney/backupbox/pull/7) Installer:** shallow clone with retries.
- **[#6](https://github.com/keeratsawhney/backupbox/pull/6) Touch UI on phones and in mobile web views** (the bottom bar was hidden).
- **[#5](https://github.com/keeratsawhney/backupbox/pull/5) Web UI header:** one row while the entries fit, otherwise the menu button.
- **[#4](https://github.com/keeratsawhney/backupbox/pull/4) Classic web UI:** tidy layout at every width; gallery like the touch UI.
- **[#3](https://github.com/keeratsawhney/backupbox/pull/3) Merge the latest original Little Backup Box;** install and update from this repository.
- **[#2](https://github.com/keeratsawhney/backupbox/pull/2) Touch UI for the box's screen and phones,** with fixes found on a Raspberry Pi 5.
- **[#1](https://github.com/keeratsawhney/backupbox/pull/1) Modern web UI:** files-first dashboard and responsive redesign.
