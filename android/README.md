# Backup Box for Android

The app finds your Backup Box and opens its touch UI (`touch.php`).

- **Finds the box**: the box used last time, the box's hotspot (10.41.0.1) and every address of the Wi-Fi's network; or type the address shown on the box's display.
- **Works on the box's hotspot**: the app keeps to the Wi-Fi even though it has no internet (Android would use mobile data otherwise).
- **Secure connection**: https with the box's own certificate, trusted the first time and pinned; a changed certificate is reported. The box's password is remembered.
- **Downloads** from the gallery are saved to the phone's Pictures / Movies (folder "Backup Box") or Download.
- **Back button** closes the touch UI's panels and tabs first.

## Install

Download `BackupBox.apk` from the release
<https://github.com/keeratsawhney/backupbox/releases/tag/android> on the phone and open it
(allow installing apps from the browser once). Newer builds install over it.

## Build

GitHub builds it on every change in `android/` on `main` (`.github/workflows/android.yml`).
Locally: Android SDK 35, JDK 17+, `cd android && ./gradlew assembleRelease`
(`local.properties` with `sdk.dir=...`).

The signing key is never committed (public repository): GitHub uses the repository secrets
`ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`;
a local build uses `android/keystore/` (ignored by git) or the debug key.
